<?php
/**
 * Backup Engine
 * 
 * Core backup functionality for WordPress files and database
 *
 * @package WP_Vault
 * @since 1.0.0
 */

namespace WP_Vault;

class WP_Vault_Backup_Engine
{
    private $backup_id;
    private $backup_type;
    private $temp_dir;
    private $backup_dir;
    private $manifest = array();
    private $api;
    private $temp_manager;
    private $compression_mode;
    private $log;

    // Tables to exclude from backup (runtime/cache tables)
    // Note: Actual table names have double prefix (wp_wp_vault_*), so we check for both
    private $excluded_tables = array(
        'wp_vault_jobs',
        'wp_vault_job_logs',
        'wp_vault_file_index',
        'wp_wp_vault_jobs',      // Handle double prefix
        'wp_wp_vault_job_logs',  // Handle double prefix
        'wp_wp_vault_file_index', // Handle double prefix
    );

    const CHUNK_SIZE = 50 * 1024 * 1024; // 50MB chunks
    const FILE_SPLIT_SIZE = 200 * 1024 * 1024; // 200MB split size

    public function __construct($backup_id, $backup_type = 'full')
    {
        $this->backup_id = $backup_id;
        $this->backup_type = $backup_type;
        $this->temp_dir = WP_VAULT_PLUGIN_DIR . 'temp/';

        // Local backup storage directory (like WPvivid: wp-content/wp-vault-backups)
        $this->backup_dir = WP_CONTENT_DIR . '/wp-vault-backups/';

        if (!file_exists($this->temp_dir)) {
            wp_mkdir_p($this->temp_dir);
        }

        if (!file_exists($this->backup_dir)) {
            wp_mkdir_p($this->backup_dir);
            // Add .htaccess to protect backups
            file_put_contents($this->backup_dir . '.htaccess', "deny from all\n");
            file_put_contents($this->backup_dir . 'index.php', "<?php\n// Silence is golden.\n");
        }

        // Initialize API client for log forwarding
        require_once WP_VAULT_PLUGIN_DIR . 'includes/class-wp-vault-api.php';
        $this->api = new WP_Vault_API();

        // Initialize temp manager
        require_once WP_VAULT_PLUGIN_DIR . 'includes/class-wp-vault-temp-manager.php';
        $this->temp_manager = new WP_Vault_Temp_Manager();

        // Get compression mode (default: 'fast')
        $this->compression_mode = get_option('wpv_compression_mode', 'fast');

        // Initialize file-based logging
        require_once WP_VAULT_PLUGIN_DIR . 'includes/class-wp-vault-log.php';
        $this->log = new WP_Vault_Log();
        $log_file_path = $this->log->create_log_file($this->backup_id, 'backup');

        // Store log file path in job record
        global $wpdb;
        $table = $wpdb->prefix . 'wp_vault_jobs';

        // Check if log_file_path column exists, if not add it
        $column_exists = $wpdb->get_results("SHOW COLUMNS FROM $table LIKE 'log_file_path'");
        if (empty($column_exists)) {
            $wpdb->query("ALTER TABLE $table ADD COLUMN log_file_path varchar(255) DEFAULT NULL AFTER error_message");
        }

        // Update log file path (best effort - don't fail if it doesn't work)
        $wpdb->update(
            $table,
            array('log_file_path' => $log_file_path),
            array('backup_id' => $this->backup_id),
            array('%s'),
            array('%s')
        );
    }

    /**
     * Execute backup
     */
    public function execute()
    {
        // Set execution time and memory limits
        @set_time_limit(0); // Unlimited (or as high as possible)
        @ini_set('max_execution_time', 0);
        $original_memory = ini_get('memory_limit');
        @ini_set('memory_limit', '512M');

        // Also try to increase via ini_set if possible
        if (function_exists('ini_set')) {
            @ini_set('max_execution_time', '0');
        }

        $this->log->write_log('===== BACKUP STARTED =====', 'info');
        $this->log->write_log('Backup ID: ' . $this->backup_id, 'info');
        $this->log->write_log('Backup Type: ' . $this->backup_type, 'info');
        $this->log->write_log('PHP Memory Limit: ' . ini_get('memory_limit'), 'info');
        $this->log->write_log('Max Execution Time: ' . ini_get('max_execution_time'), 'info');
        $this->log->write_log('Current Memory Usage: ' . size_format(memory_get_usage(true)), 'info');
        $this->log->write_log('Peak Memory Usage: ' . size_format(memory_get_peak_usage(true)), 'info');

        try {
            $this->log_progress('Starting backup...', 0);
            $this->log->write_log('Step 1: Backup initialization complete', 'info');

            // Step 1: Scan files (if files backup)
            // Skip if incremental (should use incremental flow instead)
            if ($this->backup_type === 'incremental') {
                throw new \Exception(esc_html('Incremental backups should use the incremental backup flow, not the standard backup engine'));
            }

            $files = array();
            if ($this->backup_type === 'full' || $this->backup_type === 'files') {
                $this->log_progress('Scanning files...', 10);
                $this->log->write_log('Step 2: Starting file scan...', 'info');
                $scan_start = microtime(true);
                $files = $this->scan_files();
                $scan_time = round(microtime(true) - $scan_start, 2);
                $this->log->write_log('Step 2: File scan complete. Found ' . count($files) . ' files in ' . $scan_time . ' seconds', 'info');
                $this->log->write_log('Memory after scan: ' . size_format(memory_get_usage(true)), 'info');
            }

            // Step 2: Backup database (if database backup)
            $db_file = null;
            if ($this->backup_type === 'full' || $this->backup_type === 'database') {
                $this->log_progress('Backing up database...', 30);
                $this->log_php('[WP Vault] Step 3: Starting database backup...');
                $db_start = microtime(true);
                $db_file = $this->backup_database();
                $db_time = round(microtime(true) - $db_start, 2);
                $db_size = file_exists($db_file) ? filesize($db_file) : 0;
                $this->log_php('[WP Vault] Step 3: Database backup complete. Size: ' . size_format($db_size) . ', Time: ' . $db_time . ' seconds');
                $this->log_php('[WP Vault] Memory after DB backup: ' . size_format(memory_get_usage(true)));
            }

            // Step 3: Create archive
            $this->log_progress('Creating archive...', 50);
            $this->log->write_log('Step 4: Starting archive creation...', 'info');
            $this->log->write_log('Files to archive: ' . count($files), 'info');
            $this->log->write_log('Database file: ' . ($db_file ? basename($db_file) : 'none'), 'info');
            $this->log_php('[WP Vault] Memory before archive: ' . size_format(memory_get_usage(true)));
            $archive_start = microtime(true);
            $archive_path = $this->create_archive($files, $db_file);
            $archive_time = round(microtime(true) - $archive_start, 2);
            $archive_size = file_exists($archive_path) ? filesize($archive_path) : 0;
            $this->log_php('[WP Vault] Step 4: Archive creation complete. Size: ' . size_format($archive_size) . ', Time: ' . $archive_time . ' seconds');
            $this->log_php('[WP Vault] Memory after archive: ' . size_format(memory_get_usage(true)));

            // Step 4: Save backup locally first
            $this->log_progress('Saving backup locally...', 65);
            $this->log_php('[WP Vault] Step 5: Saving backup locally...');
            $all_archives = isset($this->manifest['archives']) ? $this->manifest['archives'] : array($archive_path);
            $local_backup_files = array();
            foreach ($all_archives as $archive) {
                if (file_exists($archive)) {
                    $local_info = $this->save_local_backup($archive);
                    $local_backup_files[] = $local_info;
                }
            }

            // Save manifest file with component information
            $manifest_data = array(
                'backup_id' => $this->backup_id,
                'backup_type' => $this->backup_type,
                'compression_mode' => $this->compression_mode,
                'created_at' => current_time('mysql'),
                'total_size' => isset($this->manifest['total_size']) ? $this->manifest['total_size'] : 0,
                'components' => isset($this->manifest['components']) ? $this->manifest['components'] : array(),
                'files' => $local_backup_files,
            );

            $manifest_filename = 'backup-' . $this->backup_id . '-manifest.json';
            $manifest_path = $this->backup_dir . $manifest_filename;
            file_put_contents($manifest_path, json_encode($manifest_data, JSON_PRETTY_PRINT));

            $this->log_php('[WP Vault] Step 5: Local backup saved (' . count($local_backup_files) . ' files)');
            $this->log_php('[WP Vault] Manifest saved: ' . $manifest_filename);

            // Step 5: Upload to storage
            $this->log_progress('Uploading to storage...', 75);
            $this->log_php('[WP Vault] Step 6: Starting upload to storage...');
            $upload_start = microtime(true);
            $this->upload_to_storage($all_archives);
            $upload_time = round(microtime(true) - $upload_start, 2);
            $this->log_php('[WP Vault] Step 6: Upload complete. Time: ' . $upload_time . ' seconds');

            // Get archive size from manifest (already set in create_archive)
            $archive_size = isset($this->manifest['total_size']) ? $this->manifest['total_size'] : 0;

            // Fallback: get size from file if not in manifest
            if ($archive_size === 0 && file_exists($archive_path)) {
                $archive_size = filesize($archive_path);
                $this->manifest['total_size'] = $archive_size;
            }

            // Step 6: Clean up temp files (keep local backup)
            $this->log_progress('Cleaning up...', 95);
            $this->log_php('[WP Vault] Step 7: Cleaning up temporary files...');
            $this->cleanup($all_archives, $db_file);
            $this->log_php('[WP Vault] Step 7: Cleanup complete');

            // Update SaaS with completion status and size
            if ($this->api && $archive_size > 0) {
                $update_result = $this->api->update_job_status($this->backup_id, 'completed', $archive_size);
                if (!$update_result['success']) {
                    if (defined('WP_DEBUG') && WP_DEBUG) {
                        if (defined('WP_DEBUG') && WP_DEBUG) {
                            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                            error_log('[WP Vault] Failed to update SaaS job status: ' . ($update_result['error'] ?? 'Unknown error'));
                        }
                    }
                } else {
                    $this->log('[WP Vault] Successfully updated SaaS job status to completed with size: ' . size_format($archive_size));
                }
            }

            $this->log_progress('Backup complete!', 100, 'completed');
            $this->log->write_log('===== BACKUP COMPLETED SUCCESSFULLY =====', 'info');
            $this->log->write_log('Final Memory Usage: ' . size_format(memory_get_usage(true)), 'info');
            $this->log->write_log('Peak Memory Usage: ' . size_format(memory_get_peak_usage(true)), 'info');

            // Close log file
            $this->log->close_file();

            // Restore original memory limit
            @ini_set('memory_limit', $original_memory);

            return array(
                'success' => true,
                'backup_id' => $this->backup_id,
                'manifest' => $this->manifest,
            );

        } catch (\Exception $e) {
            $this->log->write_log('===== BACKUP FAILED =====', 'error');
            $this->log->write_log('Error: ' . $e->getMessage(), 'error');
            $this->log->write_log('File: ' . $e->getFile(), 'error');
            $this->log->write_log('Line: ' . $e->getLine(), 'error');
            $this->log->write_log('Memory at failure: ' . size_format(memory_get_usage(true)), 'error');
            $this->log->write_log('Peak memory: ' . size_format(memory_get_peak_usage(true)), 'error');
            $this->log->write_log('Stack trace: ' . $e->getTraceAsString(), 'error');

            // Close log file
            $this->log->close_file();

            $this->log_progress('Backup failed: ' . $e->getMessage(), 0, 'error');

            // Update SaaS with failure status
            if ($this->api) {
                $this->api->update_job_status($this->backup_id, 'failed');
            }

            return array(
                'success' => false,
                'error' => $e->getMessage(),
            );
        }
    }

    /**
     * Scan WordPress files
     */
    private function scan_files()
    {
        $this->log_php('[WP Vault] Starting file scan...');
        $files = array();
        $base_paths = array(
            'wp-content/themes' => ABSPATH . 'wp-content/themes',
            'wp-content/plugins' => ABSPATH . 'wp-content/plugins',
            'wp-content/uploads' => ABSPATH . 'wp-content/uploads',
        );

        $total_size = 0;
        $file_count = 0;

        foreach ($base_paths as $rel_path => $abs_path) {
            $this->log_php('[WP Vault] Scanning directory: ' . $rel_path);
            if (!is_dir($abs_path)) {
                $this->log_php('[WP Vault] Directory does not exist: ' . $abs_path);
                continue;
            }

            $dir_start = microtime(true);
            $dir_file_count = 0;
            $dir_size = 0;

            try {
                $iterator = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($abs_path, \RecursiveDirectoryIterator::SKIP_DOTS | \RecursiveDirectoryIterator::FOLLOW_SYMLINKS),
                    \RecursiveIteratorIterator::SELF_FIRST
                );

                $last_log_time = microtime(true);
                foreach ($iterator as $file) {
                    // Check for timeout every 1000 files
                    if ($file_count > 0 && $file_count % 1000 === 0) {
                        $elapsed = time() - ($_SERVER['REQUEST_TIME'] ?? time());
                        if ($elapsed > 240) {
                            $this->log_php('[WP Vault] WARNING: Execution time is ' . $elapsed . ' seconds');
                        }
                    }

                    if ($file->isFile()) {
                        try {
                            $file_path = $file->getPathname();

                            // Skip if file is not readable
                            if (!is_readable($file_path)) {
                                $this->log_php('[WP Vault] Skipping unreadable file: ' . $file_path);
                                continue;
                            }

                            // Skip if it's a symlink (can cause incorrect size reporting)
                            if (is_link($file_path)) {
                                $this->log_php('[WP Vault] Skipping symlink: ' . $file_path);
                                continue;
                            }

                            $relative_path = str_replace(ABSPATH, '', $file_path);

                            // Skip cache files and logs
                            if ($this->should_skip_file($file_path)) {
                                continue;
                            }

                            // Get file size - use filesize() for accuracy
                            $file_size = @filesize($file_path);
                            if ($file_size === false || $file_size < 0) {
                                // Fallback to iterator size if filesize fails
                                $file_size = $file->getSize();
                                // If still invalid, log and skip
                                if ($file_size === false || $file_size < 0) {
                                    $this->log_php('[WP Vault] WARNING: Could not get size for file: ' . $file_path);
                                    $file_size = 0;
                                }
                            }

                            // Validate file size (sanity check - files shouldn't be > 1GB individually for WordPress)
                            // If larger, it's likely a symlink or calculation error
                            if ($file_size > 1024 * 1024 * 1024) {
                                $this->log_php('[WP Vault] WARNING: Suspiciously large file detected: ' . $file_path . ' (' . size_format($file_size) . ') - checking if real file...');
                                // Double-check with stat
                                $stat = @stat($file_path);
                                if ($stat && isset($stat['size'])) {
                                    $real_size = $stat['size'];
                                    if ($real_size < $file_size) {
                                        $this->log_php('[WP Vault] Corrected file size: ' . size_format($real_size) . ' (was ' . size_format($file_size) . ')');
                                        $file_size = $real_size;
                                    }
                                }
                                // If still > 1GB, skip it from size calculation (but still include in backup)
                                if ($file_size > 1024 * 1024 * 1024) {
                                    $this->log_php('[WP Vault] File size still suspicious, excluding from total size calculation');
                                    // Don't add to total_size, but still add file to list
                                } else {
                                    $total_size += $file_size;
                                    $dir_size += $file_size;
                                }
                            } else {
                                $total_size += $file_size;
                                $dir_size += $file_size;
                            }

                            $total_size += $file_size;
                            $dir_size += $file_size;
                            $dir_file_count++;
                            $file_count++;

                            // Only add to files array if size is valid
                            if ($file_size >= 0) {

                                // Log every 100 files for progress, or every 5 seconds
                                $now = microtime(true);
                                if ($file_count % 100 === 0 || ($now - $last_log_time) > 5) {
                                    $this->log_php('[WP Vault] Scanned ' . $file_count . ' files so far, total size: ' . size_format($total_size) . ', Memory: ' . size_format(memory_get_usage(true)));
                                    $last_log_time = $now;

                                    // Flush output buffer to ensure logs are written
                                    if (function_exists('fastcgi_finish_request')) {
                                        @fastcgi_finish_request();
                                    }
                                    if (ob_get_level() > 0) {
                                        @ob_flush();
                                        @flush();
                                    }
                                }

                                // Skip hash calculation during scan - it's too slow and causes timeouts
                                // Hash can be calculated later if needed for incremental backups
                                $files[] = array(
                                    'path' => $file_path,
                                    'relative_path' => $relative_path,
                                    'size' => $file_size,
                                    'hash' => '', // Skip hash calculation to speed up scanning
                                    'modified' => $file->getMTime(),
                                );
                            }
                        } catch (\Exception $e) {
                            $this->log_php('[WP Vault] Error processing file: ' . $file->getPathname() . ' - ' . $e->getMessage());
                            continue; // Skip problematic files
                        }
                    }
                }
            } catch (\Exception $e) {
                $this->log_php('[WP Vault] ERROR in directory iterator for ' . $rel_path . ': ' . $e->getMessage());
                $this->log_php('[WP Vault] Exception trace: ' . $e->getTraceAsString());
                // Continue with other directories
            }

            $dir_time = round(microtime(true) - $dir_start, 2);
            $this->log_php('[WP Vault] Completed ' . $rel_path . ': ' . $dir_file_count . ' files, ' . size_format($dir_size) . ', ' . $dir_time . 's');
            $this->log_php('[WP Vault] Memory after ' . $rel_path . ': ' . size_format(memory_get_usage(true)));

            // Check execution time periodically
            $elapsed = time() - $_SERVER['REQUEST_TIME'];
            if ($elapsed > 240) { // Warn if approaching 4 minutes
                $this->log_php('[WP Vault] WARNING: Execution time is ' . $elapsed . ' seconds. Consider optimizing.');
            }
        }

        $this->log('Scanned ' . count($files) . ' files');
        $this->log_php('[WP Vault] File scan complete: ' . count($files) . ' files, total size: ' . size_format($total_size));
        $this->log_php('[WP Vault] Total scan time: ' . round(microtime(true) - ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true)), 2) . ' seconds');
        return $files;
    }

    /**
     * Check if file should be skipped
     */
    private function should_skip_file($file_path)
    {
        // Skip wp-vault plugin itself to avoid backing up backups
        if (strpos($file_path, '/wp-vault/') !== false || strpos($file_path, '/wp-vault-backups/') !== false) {
            return true;
        }

        $skip_patterns = array(
            '/cache/',
            '/logs/',
            '/tmp/',
            '/.git/',
            '/node_modules/',
            '.log',
            '/wp-vault/temp/', // Skip temp directory
        );

        foreach ($skip_patterns as $pattern) {
            if (strpos($file_path, $pattern) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Calculate SHA-256 hash of file
     * NOTE: This is currently disabled during file scanning to improve performance
     * Hash calculation can be done later if needed for incremental backups
     */
    private function calculate_file_hash($file_path)
    {
        // Skip hash calculation for now - it's too slow and causes timeouts
        // Can be enabled later for incremental backup support
        return '';

        /* Original implementation (disabled for performance):
        if (filesize($file_path) > 100 * 1024 * 1024) { // Files > 100MB
            // For large files, hash in chunks
            $hash = hash_init('sha256');
            $handle = fopen($file_path, 'rb');

            while (!feof($handle)) {
                hash_update($hash, fread($handle, 8192));
            }

            fclose($handle);
            return hash_final($hash);
        }

        return hash_file('sha256', $file_path);
        */
    }

    /**
     * Check if a table should be excluded from backup
     * 
     * @param string $table_name Table name (with or without prefix)
     * @return bool True if table should be excluded
     */
    private function is_excluded_table($table_name)
    {
        global $wpdb;
        // Remove prefix if present
        $table_name_clean = str_replace($wpdb->prefix, '', $table_name);

        // Handle double prefix case (wp_wp_vault_jobs -> wp_vault_jobs)
        if (strpos($table_name_clean, $wpdb->prefix) === 0) {
            $table_name_clean = str_replace($wpdb->prefix, '', $table_name_clean);
        }

        // Check both the cleaned name and the original (for double prefix tables)
        return in_array($table_name_clean, $this->excluded_tables) || in_array($table_name, $this->excluded_tables);
    }

    /**
     * Backup database using mysqldump
     */
    private function backup_database()
    {
        global $wpdb;

        $this->log_php('[WP Vault] Starting database backup...');
        $this->log_php('[WP Vault] DB Host: ' . DB_HOST);
        $this->log_php('[WP Vault] DB Name: ' . DB_NAME);
        $this->log_php('[WP Vault] DB User: ' . DB_USER);

        $db_file = $this->temp_dir . 'database-' . time() . '.sql';
        $this->log_php('[WP Vault] Database dump file: ' . $db_file);

        // Use mysqldump if available
        if ($this->command_exists('mysqldump')) {
            $this->log_php('[WP Vault] Using mysqldump command...');

            // Build --ignore-table flags for excluded tables
            $ignore_flags = '';
            foreach ($this->excluded_tables as $excluded_table) {
                $full_table_name = $wpdb->prefix . $excluded_table;
                $ignore_flags .= ' --ignore-table=' . escapeshellarg(DB_NAME . '.' . $full_table_name);
                $this->log->write_log('Excluding table from backup: ' . $full_table_name, 'notice');
            }

            $command = sprintf(
                'mysqldump --host=%s --user=%s --password=%s --single-transaction --routines --triggers%s %s > %s 2>&1',
                escapeshellarg(DB_HOST),
                escapeshellarg(DB_USER),
                escapeshellarg(DB_PASSWORD),
                $ignore_flags,
                escapeshellarg(DB_NAME),
                escapeshellarg($db_file)
            );

            $this->log_php('[WP Vault] Executing mysqldump...');
            $dump_start = microtime(true);
            exec($command, $output, $return_code);
            $dump_time = round(microtime(true) - $dump_start, 2);

            $this->log_php('[WP Vault] mysqldump completed in ' . $dump_time . ' seconds, return code: ' . $return_code);

            if ($return_code !== 0) {
                $error_msg = implode("\n", $output);
                $this->log_php('[WP Vault] mysqldump error output: ' . $error_msg);
                throw new \Exception(esc_html('Database backup failed: ' . $error_msg));
            }

            $dump_size = file_exists($db_file) ? filesize($db_file) : 0;
            $this->log_php('[WP Vault] Database dump size: ' . size_format($dump_size));

        } else {
            // Fallback: use WordPress database export
            $this->log_php('[WP Vault] mysqldump not available, using WordPress export...');
            $this->export_database_wp($db_file);
            $dump_size = file_exists($db_file) ? filesize($db_file) : 0;
            $this->log_php('[WP Vault] WordPress export size: ' . size_format($dump_size));
        }

        // Compress database file
        $this->log_php('[WP Vault] Compressing database file...');
        $compress_start = microtime(true);
        $compressed_file = $db_file . '.gz';
        $this->compress_file($db_file, $compressed_file);
        $compress_time = round(microtime(true) - $compress_start, 2);

        $compressed_size = file_exists($compressed_file) ? filesize($compressed_file) : 0;
        $this->log_php('[WP Vault] Compression complete in ' . $compress_time . ' seconds');
        $this->log_php('[WP Vault] Compressed size: ' . size_format($compressed_size));
        $this->log_php('[WP Vault] Compression ratio: ' . ($dump_size > 0 ? round(($compressed_size / $dump_size) * 100, 1) : 0) . '%');

        wp_delete_file($db_file);
        $this->log_php('[WP Vault] Removed uncompressed database file');

        $this->log('Database backed up: ' . size_format($compressed_size));

        return $compressed_file;
    }

    /**
     * Export database using WordPress (fallback)
     */
    private function export_database_wp($output_file)
    {
        global $wpdb;

        $tables = $wpdb->get_col('SHOW TABLES');
        $sql = '';

        foreach ($tables as $table) {
            // Skip excluded tables
            if ($this->is_excluded_table($table)) {
                $this->log->write_log('Skipping excluded table: ' . $table, 'notice');
                continue;
            }

            // Get table structure
            $create_table = $wpdb->get_row("SHOW CREATE TABLE `$table`", ARRAY_N);
            $sql .= "\n\n" . $create_table[1] . ";\n\n";

            // Get table data
            $rows = $wpdb->get_results("SELECT * FROM `$table`", ARRAY_A);

            if (!empty($rows)) {
                foreach ($rows as $row) {
                    $values = array_map(function ($value) use ($wpdb) {
                        return is_null($value) ? 'NULL' : "'" . $wpdb->_real_escape($value) . "'";
                    }, array_values($row));

                    $sql .= "INSERT INTO `$table` VALUES (" . implode(', ', $values) . ");\n";
                }
            }
        }

        file_put_contents($output_file, $sql);
    }

    /**
     * Create backup archive (component-based)
     * Organizes files by component and creates separate archives
     */
    private function create_archive($files, $db_file)
    {
        $this->log_php('[WP Vault] ===== ARCHIVE CREATION STARTED =====');
        $this->log_php('[WP Vault] Compression mode: ' . $this->compression_mode);
        $this->log_php('[WP Vault] Files to archive: ' . count($files));
        $this->log_php('[WP Vault] Database file: ' . ($db_file ? basename($db_file) : 'none'));

        // Organize files by component
        $components = $this->organize_files_by_component($files, $db_file);
        $this->log_php('[WP Vault] Organized into ' . count($components) . ' components');

        // Get split size from settings
        $split_size = get_option('wpv_file_split_size', 200) * 1024 * 1024; // Convert MB to bytes

        // Create archives for each component
        $all_archives = array();
        $total_size = 0;
        $components_list = array();

        // Priority order: Database, Themes, Plugins, Uploads, WP-Content
        $priority_order = array('database', 'themes', 'plugins', 'uploads', 'wp-content');

        foreach ($priority_order as $component_name) {
            // Skip if component is empty (database is handled specially)
            if ($component_name === 'database') {
                if (empty($components['database'])) {
                    continue;
                }
                $component_files = array();
            } else {
                if (!isset($components[$component_name]) || empty($components[$component_name])) {
                    continue;
                }
                $component_files = $components[$component_name];
            }

            $this->log_progress('Creating ' . $component_name . ' archive...', 50 + (array_search($component_name, $priority_order) * 10));
            $this->save_state('archiving', $component_name);

            $component_archives = $this->create_component_archive($component_name, $component_files, $components['database'], $split_size);

            $all_archives = array_merge($all_archives, $component_archives);
            foreach ($component_archives as $archive) {
                $total_size += filesize($archive);
            }

            $components_list[] = array(
                'name' => $component_name,
                'files' => $component_name === 'database' ? 1 : count($component_files),
                'archives' => $component_archives,
            );
        }

        // Save components list
        $this->save_components($components_list);

        $this->log_php('[WP Vault] Created ' . count($all_archives) . ' archive files');
        $this->log_php('[WP Vault] Total size: ' . size_format($total_size));
        $this->log_php('[WP Vault] ===== ARCHIVE CREATION COMPLETE =====');

        $this->manifest = array(
            'backup_id' => $this->backup_id,
            'backup_type' => $this->backup_type,
            'compression_mode' => $this->compression_mode,
            'file_count' => count($files),
            'total_size' => $total_size,
            'components' => $components_list,
            'archives' => $all_archives,
            'created_at' => current_time('mysql'),
        );

        // Save size to local database
        global $wpdb;
        $table = $wpdb->prefix . 'wp_vault_jobs';
        $wpdb->update(
            $table,
            array(
                'total_size_bytes' => $total_size,
                'compression_mode' => $this->compression_mode,
            ),
            array('backup_id' => $this->backup_id),
            array('%d', '%s'),
            array('%s')
        );

        // Return primary archive path (first one, or combined if single archive mode)
        return !empty($all_archives) ? $all_archives[0] : null;
    }

    /**
     * Organize files by component
     */
    private function organize_files_by_component($files, $db_file = null)
    {
        $components = array(
            'database' => null,
            'themes' => array(),
            'plugins' => array(),
            'uploads' => array(),
            'wp-content' => array(),
        );

        // Add database file if present
        if ($db_file && file_exists($db_file)) {
            $components['database'] = $db_file;
        }

        foreach ($files as $file) {
            $relative_path = $file['relative_path'];

            if (strpos($relative_path, 'wp-content/themes/') === 0) {
                $components['themes'][] = $file;
            } elseif (strpos($relative_path, 'wp-content/plugins/') === 0) {
                $components['plugins'][] = $file;
            } elseif (strpos($relative_path, 'wp-content/uploads/') === 0) {
                $components['uploads'][] = $file;
            } elseif (strpos($relative_path, 'wp-content/') === 0) {
                $components['wp-content'][] = $file;
            }
        }

        return $components;
    }

    /**
     * Create archive for a specific component
     */
    private function create_component_archive($component_name, $files, $db_file, $split_size)
    {
        $extension = ($this->compression_mode === 'fast') ? 'tar.gz' : 'zip';
        $base_path = $this->temp_dir . $component_name . '-' . $this->backup_id . '.' . $extension;

        if ($this->compression_mode === 'fast') {
            return $this->create_component_archive_fast($component_name, $files, $db_file, $base_path, $split_size);
        } else {
            return $this->create_component_archive_legacy($component_name, $files, $db_file, $base_path, $split_size);
        }
    }

    /**
     * Create component archive using Fast mode (tar.gz)
     */
    private function create_component_archive_fast($component_name, $files, $db_file, $base_path, $split_size)
    {
        // For database component, just copy the db file (it's already compressed as .sql.gz)
        if ($component_name === 'database' && $db_file) {
            if (file_exists($db_file)) {
                // Save database file with .sql.gz extension (not .tar.gz)
                $db_filename = 'database-' . $this->backup_id . '.sql.gz';
                $db_path = dirname($base_path) . '/' . $db_filename;
                copy($db_file, $db_path);
                $this->log_php('[WP Vault] Database file saved: ' . $db_filename);
                return array($db_path);
            }
            return array();
        }

        // Use existing tar method for file components
        if (empty($files)) {
            return array();
        }

        $this->create_archive_with_tar($files, null, $base_path);

        // Split if needed
        if (file_exists($base_path) && filesize($base_path) > $split_size) {
            return $this->split_archive($base_path, $split_size);
        }

        return array($base_path);
    }

    /**
     * Create component archive using Legacy mode (ZIP)
     */
    private function create_component_archive_legacy($component_name, $files, $db_file, $base_path, $split_size)
    {
        require_once WP_VAULT_PLUGIN_DIR . 'includes/compression/class-wp-vault-zip-compressor.php';
        $compressor = new \WP_Vault\Compression\WP_Vault_Zip_Compressor(
            $this->temp_dir,
            array($this, 'log_php')
        );

        // For database, save as .sql.gz (not .zip)
        if ($component_name === 'database' && $db_file) {
            if (file_exists($db_file)) {
                // Save database file with .sql.gz extension
                $db_filename = 'database-' . $this->backup_id . '.sql.gz';
                $db_path = dirname($base_path) . '/' . $db_filename;
                copy($db_file, $db_path);
                $this->log_php('[WP Vault] Database file saved: ' . $db_filename);
                return array($db_path);
            }
            return array();
        }

        $result = $compressor->create_archive($files, $base_path, $split_size);
        return $result['archives'];
    }

    /**
     * Split archive into parts
     */
    private function split_archive($archive_path, $split_size)
    {
        $this->log_php('[WP Vault] Splitting archive: ' . basename($archive_path));

        if ($this->command_exists('split')) {
            // Use split command
            $part_prefix = $archive_path . '.part';
            $split_cmd = sprintf(
                'split -b %d %s %s',
                $split_size,
                escapeshellarg($archive_path),
                escapeshellarg($part_prefix)
            );

            exec($split_cmd, $output, $return_code);

            if ($return_code === 0) {
                // Find all parts
                $parts = glob($part_prefix . '*');
                sort($parts);
                return $parts;
            }
        }

        // Fallback: manual splitting
        return $this->split_archive_manual($archive_path, $split_size);
    }

    /**
     * Manually split archive (fallback)
     */
    private function split_archive_manual($archive_path, $split_size)
    {
        $parts = array();
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Binary file splitting requires direct file access for streaming
        $handle = fopen($archive_path, 'rb');
        $part_number = 1;

        while (!feof($handle)) {
            $part_path = $archive_path . '.part' . sprintf('%03d', $part_number);
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Binary file splitting requires direct file access for streaming
            $part_handle = fopen($part_path, 'wb');
            $bytes_written = 0;

            while ($bytes_written < $split_size && !feof($handle)) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Binary file splitting requires chunked reading
                $chunk = fread($handle, min(8192, $split_size - $bytes_written));
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Binary file splitting requires chunked writing
                fwrite($part_handle, $chunk);
                $bytes_written += strlen($chunk);
            }

            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Binary file splitting requires direct file access
            fclose($part_handle);
            $parts[] = $part_path;
            $part_number++;
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Binary file splitting requires direct file access
        fclose($handle);
        wp_delete_file($archive_path); // Remove original

        return $parts;
    }

    /**
     * Save backup locally in wp-content/wp-vault-backups
     */
    private function save_local_backup($archive_path)
    {
        $this->log_php('[WP Vault] save_local_backup: Starting...');

        // Extract component name from archive path (e.g., themes-{id}.tar.gz or database-{id}.sql.gz)
        $basename = basename($archive_path);
        $name_without_ext = pathinfo($basename, PATHINFO_FILENAME);

        // Check if it's a component file (has component prefix)
        $component_match = preg_match('/^([a-z-]+)-' . preg_quote($this->backup_id, '/') . '/', $name_without_ext, $matches);

        if ($component_match && isset($matches[1])) {
            // Component file: preserve original extension
            $component_name = $matches[1];

            // Handle special extensions: .tar.gz and .sql.gz
            if (substr($basename, -7) === '.tar.gz') {
                $extension = 'tar.gz';
            } elseif (substr($basename, -7) === '.sql.gz') {
                $extension = 'sql.gz';
            } else {
                $extension = pathinfo($basename, PATHINFO_EXTENSION);
            }

            $filename = $component_name . '-' . $this->backup_id . '-' . gmdate('Y-m-d-His') . '.' . $extension;
        } else {
            // Legacy single file backup or use original filename
            if (substr($basename, -7) === '.tar.gz') {
                $extension = 'tar.gz';
            } elseif (substr($basename, -7) === '.sql.gz') {
                $extension = 'sql.gz';
            } else {
                $extension = pathinfo($basename, PATHINFO_EXTENSION);
            }
            $filename = 'backup-' . $this->backup_id . '-' . gmdate('Y-m-d-His') . '.' . $extension;
        }

        $local_path = $this->backup_dir . $filename;
        $this->log_php('[WP Vault] Local backup path: ' . $local_path);
        $this->log_php('[WP Vault] Source archive size: ' . size_format(filesize($archive_path)));

        $copy_start = microtime(true);
        if (!copy($archive_path, $local_path)) {
            $this->log_php('[WP Vault] ERROR: Failed to copy archive to local backup directory');
            throw new \Exception(esc_html('Failed to save backup locally'));
        }
        $copy_time = round(microtime(true) - $copy_start, 2);
        $local_size = filesize($local_path);
        $this->log_php('[WP Vault] Copy completed in ' . $copy_time . ' seconds');
        $this->log_php('[WP Vault] Local backup size: ' . size_format($local_size));

        $this->log('Backup saved locally: ' . $filename . ' (' . size_format($local_size) . ')');
        return array(
            'path' => $local_path,
            'filename' => $filename,
            'size' => $local_size,
        );
    }

    /**
     * Upload archives to configured storage
     */
    private function upload_to_storage($archives)
    {
        $this->log_php('[WP Vault] upload_to_storage: Starting...');
        // Use primary storage type, fallback to configured storage type, then default to GCS
        $primary_storage = get_option('wpv_primary_storage_type', '');
        $storage_type = !empty($primary_storage)
            ? $primary_storage
            : get_option('wpv_storage_type', 'gcs');
        $this->log_php('[WP Vault] Storage type: ' . $storage_type);
        $storage_config = $this->get_storage_config($storage_type);
        $this->log_php('[WP Vault] Storage config retrieved');

        require_once WP_VAULT_PLUGIN_DIR . 'includes/storage/class-storage-factory.php';
        $storage = \WP_Vault\Storage\Storage_Factory::create($storage_type, $storage_config);
        $this->log_php('[WP Vault] Storage adapter created');

        $site_id = get_option('wpv_site_id', '');
        $uploaded_paths = array();
        $upload_count = 0;

        foreach ($archives as $index => $archive_path) {
            if (!file_exists($archive_path)) {
                continue;
            }

            // Generate remote path based on storage type
            if ($storage_type === 'gcs') {
                // For component-based backups, include component name and part number
                $filename = basename($archive_path);
                $chunk_number = sprintf('%04d', $index);
                $remote_path = "backups/tenant/{$site_id}/{$this->backup_id}/chunk-{$chunk_number}.tar.gz";
            } else {
                // For other storage types, use simple path
                $remote_path = 'backups/' . $site_id . '/' . basename($archive_path);
            }

            $this->log_php('[WP Vault] Uploading: ' . basename($archive_path) . ' (' . size_format(filesize($archive_path)) . ')');
            $upload_start = microtime(true);
            $result = $storage->upload($archive_path, $remote_path);
            $upload_time = round(microtime(true) - $upload_start, 2);

            if (!$result['success']) {
                $this->log_php('[WP Vault] ERROR: Storage upload failed for ' . basename($archive_path));
                $this->log_php('[WP Vault] Error: ' . ($result['error'] ?? 'Unknown error'));
                throw new \Exception(esc_html('Storage upload failed: ' . ($result['error'] ?? 'Unknown error')));
            }

            $uploaded_paths[] = $remote_path;
            $upload_count++;
            $this->log_php('[WP Vault] Uploaded ' . $upload_count . '/' . count($archives) . ' in ' . $upload_time . ' seconds');
        }

        $this->manifest['storage_paths'] = $uploaded_paths;
        $this->log('Uploaded ' . $upload_count . ' files to storage');
        $this->log_php('[WP Vault] All uploads successful');
    }

    /**
     * Get storage configuration
     */
    private function get_storage_config($type)
    {
        switch ($type) {
            case 'gcs':
                // GCS (WP Vault Cloud) uses API endpoint and site token
                return array(
                    'api_endpoint' => get_option('wpv_api_endpoint', 'http://host.docker.internal:3000'),
                    'site_token' => get_option('wpv_site_token', ''),
                );
            case 's3':
                return array(
                    'endpoint' => get_option('wpv_s3_endpoint'),
                    'bucket' => get_option('wpv_s3_bucket'),
                    'access_key' => get_option('wpv_s3_access_key'),
                    'secret_key' => get_option('wpv_s3_secret_key'),
                    'region' => get_option('wpv_s3_region'),
                );
            default:
                throw new \Exception(esc_html('Unsupported storage type: ' . $type));
        }
    }

    /**
     * Compress file using gzip
     */
    private function compress_file($source, $destination)
    {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Gzip compression requires direct file access for streaming
        $fp_in = fopen($source, 'rb');
        $fp_out = gzopen($destination, 'wb9');

        while (!feof($fp_in)) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Gzip compression requires chunked reading
            gzwrite($fp_out, fread($fp_in, 8192));
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Gzip compression requires direct file access
        fclose($fp_in);
        gzclose($fp_out);
    }

    /**
     * Create archive using system tar command (memory efficient - streams data)
     * This avoids loading the entire archive into memory like PharData::compress() does
     */
    private function create_archive_with_tar($files, $db_file, $archive_path)
    {
        $this->log_php('[WP Vault] create_archive_with_tar: Starting...');
        $wp_root = rtrim(ABSPATH, '/');
        $this->log_php('[WP Vault] WordPress root: ' . $wp_root);

        // Create a file list to avoid command line length limits
        $file_list_path = $this->temp_dir . 'file-list-' . $this->backup_id . '.txt';
        $this->log_php('[WP Vault] Creating file list: ' . $file_list_path);
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- File list creation requires direct file access for performance
        $file_list = fopen($file_list_path, 'w');

        if (!$file_list) {
            $this->log_php('[WP Vault] ERROR: Failed to create file list file');
            throw new \Exception(esc_html('Failed to create file list for tar'));
        }

        // Write all file paths (relative to wp_root)
        $files_written = 0;
        $this->log_php('[WP Vault] Writing file paths to list...');
        foreach ($files as $file) {
            if (file_exists($file['path'])) {
                $abs_path = realpath($file['path']);
                if ($abs_path) {
                    // Get relative path from WordPress root
                    $rel_path = str_replace($wp_root . '/', '', $abs_path);
                    if ($rel_path !== $abs_path && !empty($rel_path)) {
                        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- File list creation requires direct file access for performance
                        fwrite($file_list, $rel_path . "\n");
                        $files_written++;
                    }
                }
            }
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- File list creation requires direct file access
        fclose($file_list);
        $this->log_php('[WP Vault] File list created with ' . $files_written . ' files');
        $list_size = filesize($file_list_path);
        $this->log_php('[WP Vault] File list size: ' . size_format($list_size));

        // Build tar command
        // IMPORTANT: Archive path must be OUTSIDE the WordPress root to avoid including itself
        // Change to wp_root directory so relative paths work correctly
        // Use file list to avoid command line length issues
        $tar_cmd = sprintf(
            'cd %s && tar -czf %s -T %s 2>&1',
            escapeshellarg($wp_root),
            escapeshellarg($archive_path),
            escapeshellarg($file_list_path)
        );

        // Add database file separately with transform to rename it
        if ($db_file && file_exists($db_file)) {
            $this->log_php('[WP Vault] Adding database file to archive...');
            $db_abs = realpath($db_file);
            if ($db_abs) {
                $db_rel = str_replace($wp_root . '/', '', $db_abs);
                if ($db_rel !== $db_abs && !empty($db_rel)) {
                    // Add database file with transform to rename it to database.sql.gz
                    // Escape special regex characters in the path for the transform
                    $db_transform = 's|^' . preg_quote($db_rel, '|') . '$|database.sql.gz|';
                    $tar_cmd = sprintf(
                        'cd %s && tar -czf %s --transform %s %s -T %s 2>&1',
                        escapeshellarg($wp_root),
                        escapeshellarg($archive_path),
                        escapeshellarg($db_transform),
                        escapeshellarg($db_rel),
                        escapeshellarg($file_list_path)
                    );
                    $this->log_php('[WP Vault] Database file will be renamed to database.sql.gz in archive');
                    $this->log_php('[WP Vault] Database relative path: ' . $db_rel);
                } else {
                    $this->log_php('[WP Vault] WARNING: Could not determine database relative path');
                }
            }
        }

        $this->log_php('[WP Vault] Executing tar command...');
        $this->log_php('[WP Vault] Command: ' . $tar_cmd);
        $tar_start = microtime(true);
        $this->log_php('[WP Vault] Memory before tar: ' . size_format(memory_get_usage(true)));

        // Calculate estimated total size for progress tracking
        $estimated_size = 0;
        foreach ($files as $file) {
            if (file_exists($file['path'])) {
                $estimated_size += $file['size'];
            }
        }
        if ($db_file && file_exists($db_file)) {
            $estimated_size += filesize($db_file);
        }
        $this->log_php('[WP Vault] Estimated archive size: ' . size_format($estimated_size));
        $this->log_php('[WP Vault] This may take several minutes for large backups...');

        // Run tar command synchronously with progress monitoring
        // For a 14MB backup, this should complete quickly
        $this->log_php('[WP Vault] Executing tar command synchronously...');
        $this->log_php('[WP Vault] Note: Archive path is correct (outside WordPress root)');

        // Execute tar command - this will block until complete
        // For small backups (< 100MB), this should be fast
        exec($tar_cmd, $output, $return_var);

        $tar_time = round(microtime(true) - $tar_start, 2);
        $this->log_php('[WP Vault] Tar command completed in ' . $tar_time . ' seconds');
        $this->log_php('[WP Vault] Return code: ' . $return_var);
        $this->log_php('[WP Vault] Memory after tar: ' . size_format(memory_get_usage(true)));

        if (!empty($output)) {
            $this->log_php('[WP Vault] Tar output (last 20 lines): ' . implode("\n", array_slice($output, -20)));
        }

        if ($return_var !== 0) {
            $error_msg = !empty($output) ? implode("\n", $output) : 'Unknown tar error';
            $this->log_php('[WP Vault] ERROR: Tar command failed');
            $this->log_php('[WP Vault] Error details: ' . $error_msg);
            throw new \Exception(esc_html('Tar command failed (exit code ' . $return_var . '): ' . $error_msg));
        }

        // Clean up file list
        wp_delete_file($file_list_path);
        $this->log_php('[WP Vault] Cleaned up file list');

        // Verify archive was created and check its size
        if (!file_exists($archive_path)) {
            $this->log_php('[WP Vault] ERROR: Archive file was not created after tar command');
            throw new \Exception('Tar command completed but archive file was not created');
        }

        $final_size = filesize($archive_path);
        $this->log_php('[WP Vault] Archive file created successfully. Final size: ' . size_format($final_size));

        // Verify archive is not empty
        if ($final_size === 0) {
            $this->log_php('[WP Vault] ERROR: Archive file is empty!');
            throw new \Exception('Archive file was created but is empty');
        }

        // Log compression ratio
        if ($estimated_size > 0) {
            $compression_ratio = round(($final_size / $estimated_size) * 100, 1);
            $this->log_php('[WP Vault] Compression ratio: ' . $compression_ratio . '% of original size');
        }
    }

    /**
     * Create archive using PharData (fallback, with memory management)
     */
    private function create_archive_with_phar($files, $db_file, $archive_path)
    {
        $this->log_php('[WP Vault] create_archive_with_phar: Starting...');
        $tar_path = $this->temp_dir . 'backup-' . $this->backup_id . '.tar';
        $this->log_php('[WP Vault] Tar path: ' . $tar_path);

        // Ensure clean state
        if (file_exists($tar_path)) {
            $this->log_php('[WP Vault] Removing existing tar file...');
            wp_delete_file($tar_path);
        }

        // Increase memory limit temporarily for compression
        $original_memory_limit = ini_get('memory_limit');
        $this->log_php('[WP Vault] Original memory limit: ' . $original_memory_limit);
        ini_set('memory_limit', '512M');
        $this->log_php('[WP Vault] Increased memory limit to 512M');

        try {
            $this->log_php('[WP Vault] Creating PharData object...');
            // Create tar archive
            $phar = new \PharData($tar_path);
            $this->log_php('[WP Vault] PharData object created');

            // Add database
            if ($db_file && file_exists($db_file)) {
                $this->log_php('[WP Vault] Adding database file to archive...');
                $phar->addFile($db_file, 'database.sql.gz');
                $this->log_php('[WP Vault] Database file added');
            }

            // Add files in batches to avoid memory issues
            $batch_size = 100;
            $batch = array();
            $files_added = 0;
            $this->log_php('[WP Vault] Adding files in batches of ' . $batch_size . '...');

            foreach ($files as $file) {
                if (file_exists($file['path'])) {
                    $batch[] = $file;

                    if (count($batch) >= $batch_size) {
                        $this->log_php('[WP Vault] Processing batch of ' . count($batch) . ' files...');
                        $batch_start = microtime(true);
                        foreach ($batch as $batch_file) {
                            $phar->addFile($batch_file['path'], $batch_file['relative_path']);
                            $files_added++;
                        }
                        $batch_time = round(microtime(true) - $batch_start, 2);
                        $this->log_php('[WP Vault] Batch added in ' . $batch_time . 's. Total files: ' . $files_added . ', Memory: ' . size_format(memory_get_usage(true)));
                        $batch = array();

                        // Force garbage collection periodically
                        if (function_exists('gc_collect_cycles')) {
                            gc_collect_cycles();
                        }
                    }
                }
            }

            // Add remaining files
            if (!empty($batch)) {
                $this->log_php('[WP Vault] Adding final batch of ' . count($batch) . ' files...');
                foreach ($batch as $batch_file) {
                    $phar->addFile($batch_file['path'], $batch_file['relative_path']);
                    $files_added++;
                }
                $this->log_php('[WP Vault] Final batch added. Total files: ' . $files_added);
            }

            $tar_size = file_exists($tar_path) ? filesize($tar_path) : 0;
            $this->log_php('[WP Vault] Uncompressed tar created. Size: ' . size_format($tar_size));
            $this->log_php('[WP Vault] Memory before compression: ' . size_format(memory_get_usage(true)));

            // Compress to .tar.gz using system gzip (streams, doesn't load into memory)
            // This avoids the memory exhaustion issue with PharData::compress()
            if ($this->command_exists('gzip')) {
                $this->log_php('[WP Vault] Using system gzip for compression (streaming)...');
                // Use system gzip which streams the compression
                $gzip_cmd = sprintf('gzip -c %s > %s 2>&1', escapeshellarg($tar_path), escapeshellarg($archive_path));
                $this->log_php('[WP Vault] Gzip command: ' . $gzip_cmd);
                $gzip_start = microtime(true);
                exec($gzip_cmd, $gzip_output, $gzip_return);
                $gzip_time = round(microtime(true) - $gzip_start, 2);
                $this->log_php('[WP Vault] Gzip completed in ' . $gzip_time . 's, return code: ' . $gzip_return);
                $this->log_php('[WP Vault] Memory after gzip: ' . size_format(memory_get_usage(true)));

                if ($gzip_return === 0 && file_exists($archive_path)) {
                    $this->log_php('[WP Vault] Compression successful via gzip');
                    // Remove uncompressed tar
                    wp_delete_file($tar_path);
                } else {
                    $this->log_php('[WP Vault] Gzip failed, falling back to PharData::compress()...');
                    // Fallback to PharData compress if gzip fails
                    $phar->compress(\Phar::GZ);
                    if (file_exists($tar_path)) {
                        wp_delete_file($tar_path);
                    }
                }
            } else {
                $this->log_php('[WP Vault] gzip command not available, using PharData::compress() (WARNING: memory intensive)...');
                // No gzip command available, use PharData (with increased memory)
                ini_set('memory_limit', '1024M'); // Increase further for compression
                $this->log_php('[WP Vault] Increased memory limit to 1024M for PharData compression');
                $compress_start = microtime(true);
                $phar->compress(\Phar::GZ);
                $compress_time = round(microtime(true) - $compress_start, 2);
                $this->log_php('[WP Vault] PharData compression completed in ' . $compress_time . 's');
                $this->log_php('[WP Vault] Memory after PharData compression: ' . size_format(memory_get_usage(true)));
                if (file_exists($tar_path)) {
                    wp_delete_file($tar_path);
                }
            }
        } catch (\Exception $e) {
            $this->log_php('[WP Vault] ERROR in create_archive_with_phar: ' . $e->getMessage());
            $this->log_php('[WP Vault] Exception file: ' . $e->getFile() . ':' . $e->getLine());
            throw $e;
        } finally {
            // Restore original memory limit
            ini_set('memory_limit', $original_memory_limit);
            $this->log_php('[WP Vault] Restored memory limit to: ' . $original_memory_limit);
        }
    }

    /**
     * Delete directory recursively
     */
    private function delete_directory($dir)
    {
        if (!file_exists($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), array('.', '..'));
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                $this->delete_directory($path);
            } else {
                wp_delete_file($path);
            }
        }
        // Use WP_Filesystem for directory removal
        global $wp_filesystem;
        if (empty($wp_filesystem)) {
            require_once ABSPATH . '/wp-admin/includes/file.php';
            WP_Filesystem();
        }
        if ($wp_filesystem) {
            $wp_filesystem->rmdir($dir, true);
        } else {
            // Fallback if WP_Filesystem is not available
            @rmdir($dir); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
        }
    }

    /**
     * Check if command exists
     */
    private function command_exists($command)
    {
        $path = exec("which $command 2>/dev/null");
        $exists = !empty($path);
        $this->log_php('[WP Vault] Command check - ' . $command . ': ' . ($exists ? 'YES (' . $path . ')' : 'NO'));
        return $exists;
    }

    /**
     * Clean up temporary files
     */
    private function cleanup($archives, $db_file)
    {
        $this->log_php('[WP Vault] cleanup: Starting...');

        // Clean up all archive files
        if (is_array($archives)) {
            foreach ($archives as $archive_path) {
                if (file_exists($archive_path)) {
                    $this->log_php('[WP Vault] Removing temp archive: ' . basename($archive_path));
                    wp_delete_file($archive_path);
                }
            }
        } elseif (file_exists($archives)) {
            $this->log_php('[WP Vault] Removing temp archive: ' . basename($archives));
            wp_delete_file($archives);
        }

        if ($db_file && file_exists($db_file)) {
            $this->log_php('[WP Vault] Removing temp database file: ' . basename($db_file));
            wp_delete_file($db_file);
        }
        $this->log_php('[WP Vault] Cleanup complete');
    }

    /**
     * Log progress to database
     */
    private function log_progress($message, $percent, $status = 'running', $step = null, $metadata = array())
    {
        global $wpdb;

        $table = $wpdb->prefix . 'wp_vault_jobs';
        $logs_table = $wpdb->prefix . 'wp_vault_job_logs';
        $severity = $status === 'error' ? 'ERROR' : 'INFO';

        $update_data = array(
            'status' => $status,
            'progress_percent' => $percent,
            'error_message' => $status === 'error' ? $message : null,
        );
        $format = array('%s', '%d', '%s');

        // Set finished_at when completed or failed
        if ($status === 'completed' || $status === 'failed') {
            $update_data['finished_at'] = current_time('mysql');
            $format[] = '%s';
        }

        // If we have size in manifest, include it
        if (isset($this->manifest['total_size']) && $this->manifest['total_size'] > 0) {
            $update_data['total_size_bytes'] = $this->manifest['total_size'];
            $format[] = '%d';
        }

        $wpdb->update(
            $table,
            $update_data,
            array('backup_id' => $this->backup_id),
            $format,
            array('%s')
        );

        // Log to file instead of database
        if ($this->log) {
            $log_level = ($status === 'error') ? 'error' : (($status === 'completed') ? 'notice' : 'info');
            $this->log->write_log($message . ' (' . $percent . '%)', $log_level);
        }

        // Forward to SaaS (best-effort, non-blocking)
        if ($this->api) {
            $this->api->send_log($this->backup_id, array(
                'severity' => $severity,
                'step' => $step,
                'message' => $message,
                'percent' => $percent,
                'metadata' => $metadata,
            ));
        }

        $this->log($message . ' (' . $percent . '%)');
    }

    /**
     * Simple logger
     */
    private function log($message)
    {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log('[WP Vault Backup] ' . $message);
        }
    }

    /**
     * Log to PHP error log (visible in Docker logs) and file log
     * This is only enabled when WP_DEBUG is true
     */
    private function log_php($message)
    {
        $timestamp = gmdate('Y-m-d H:i:s');
        if (defined('WP_DEBUG') && WP_DEBUG) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log('[' . $timestamp . '] ' . $message);
        }

        // Also write to file log if available
        if ($this->log) {
            // Remove [WP Vault] prefix if present for cleaner logs
            $clean_message = preg_replace('/^\[WP Vault\]\s*/', '', $message);
            $level = (stripos($message, 'ERROR') !== false) ? 'error' :
                ((stripos($message, 'WARNING') !== false) ? 'warning' : 'info');
            $this->log->write_log($clean_message, $level);
        }
    }

    /**
     * Save backup state for resuming
     */
    private function save_state($step, $component = null, $offset = 0, $resume_data = array())
    {
        global $wpdb;
        $table = $wpdb->prefix . 'wp_vault_jobs';

        $update_data = array(
            'current_step' => $step,
            'current_offset' => $offset,
            'updated_at' => current_time('mysql'),
        );

        if ($component) {
            $update_data['current_component'] = $component;
        }

        if (!empty($resume_data)) {
            $update_data['resume_data'] = json_encode($resume_data);
        }

        $wpdb->update(
            $table,
            $update_data,
            array('backup_id' => $this->backup_id),
            array('%s', '%d', '%s', '%s', '%s'),
            array('%s')
        );
    }

    /**
     * Get saved state
     */
    private function get_state()
    {
        global $wpdb;
        $table = $wpdb->prefix . 'wp_vault_jobs';

        $job = $wpdb->get_row($wpdb->prepare(
            "SELECT current_step, current_component, current_offset, resume_data, compression_mode FROM $table WHERE backup_id = %s",
            $this->backup_id
        ));

        if ($job) {
            return array(
                'step' => $job->current_step,
                'component' => $job->current_component,
                'offset' => (int) $job->current_offset,
                'resume_data' => !empty($job->resume_data) ? json_decode($job->resume_data, true) : array(),
                'compression_mode' => $job->compression_mode ?: 'fast',
            );
        }

        return array(
            'step' => null,
            'component' => null,
            'offset' => 0,
            'resume_data' => array(),
            'compression_mode' => 'fast',
        );
    }

    /**
     * Save components list
     */
    private function save_components($components)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'wp_vault_jobs';

        $wpdb->update(
            $table,
            array(
                'components' => json_encode($components),
                'updated_at' => current_time('mysql'),
            ),
            array('backup_id' => $this->backup_id),
            array('%s', '%s'),
            array('%s')
        );
    }
}
