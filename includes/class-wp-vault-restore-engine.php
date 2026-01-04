<?php
/**
 * Restore Engine
 * 
 * Handles downloading and restoring WordPress backups
 *
 * @package WP_Vault
 * @since 1.0.0
 */

namespace WP_Vault;

class WP_Vault_Restore_Engine
{
    private $backup_id;
    private $backup_file;
    private $restore_mode;
    private $temp_dir;
    private $backup_dir;
    private $restore_id;
    private $restore_options;
    private $log;

    // Tables to exclude from restore (runtime/cache tables + history tracking)
    // Note: Actual table names have double prefix (wp_wp_vault_*), so we check for both
    // CRITICAL: History tables must be excluded to prevent overwriting current restore status
    private $excluded_tables = array(
        'wp_vault_jobs',
        'wp_vault_job_logs',
        'wp_vault_file_index',
        'wp_vault_backup_history',      // History tracking - must not be overwritten
        'wp_vault_restore_history',     // History tracking - must not be overwritten
        'wp_wp_vault_jobs',             // Handle double prefix
        'wp_wp_vault_job_logs',         // Handle double prefix
        'wp_wp_vault_file_index',       // Handle double prefix
        'wp_wp_vault_backup_history',   // Handle double prefix
        'wp_wp_vault_restore_history',  // Handle double prefix
        'wp_pc_wp_vault_jobs',          // Handle prefixed installations
        'wp_pc_wp_vault_backup_history', // Handle prefixed installations
        'wp_pc_wp_vault_restore_history', // Handle prefixed installations
    );

    public function __construct($backup_file, $restore_mode = 'full', $restore_id = null, $restore_options = array())
    {
        $this->backup_file = $backup_file;
        $this->restore_mode = $restore_mode;
        $this->restore_id = $restore_id;
        $this->restore_options = $restore_options;
        $this->temp_dir = WP_VAULT_PLUGIN_DIR . 'temp/';
        $this->backup_dir = WP_CONTENT_DIR . '/wp-vault-backups/';

        if (!file_exists($this->temp_dir)) {
            wp_mkdir_p($this->temp_dir);
        }

        // Extract backup_id from filename if needed
        if (preg_match('/backup-([a-zA-Z0-9_-]+)-/', basename($backup_file), $matches)) {
            $this->backup_id = $matches[1];
        } elseif (preg_match('/(database|themes|plugins|uploads|wp-content)-([a-zA-Z0-9_-]+)-/', basename($backup_file), $matches)) {
            // Component file: extract backup_id from component filename
            $this->backup_id = $matches[2];
        } else {
            $this->backup_id = basename($backup_file, '.tar.gz');
            $this->backup_id = basename($this->backup_id, '.zip');
        }

        // Get backup_id from restore options if provided
        if (isset($restore_options['backup_id']) && !empty($restore_options['backup_id'])) {
            $this->backup_id = $restore_options['backup_id'];
        }

        // Initialize file-based logging
        require_once WP_VAULT_PLUGIN_DIR . 'includes/class-wp-vault-log.php';
        $this->log = new WP_Vault_Log();

        // Use restore_id if available, otherwise generate one
        $log_job_id = $this->restore_id ?: ($this->backup_id ?: 'restore-' . time());
        $log_file_path = $this->log->create_log_file($log_job_id, 'restore');

        // Store log file path in job record if restore_id exists
        if ($this->restore_id) {
            global $wpdb;
            $table = $wpdb->prefix . 'wp_vault_jobs';

            // Check if log_file_path column exists, if not add it
            $column_exists = $wpdb->get_results("SHOW COLUMNS FROM $table LIKE 'log_file_path'");
            if (empty($column_exists)) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
                $wpdb->query("ALTER TABLE $table ADD COLUMN log_file_path varchar(255) DEFAULT NULL AFTER error_message");
            }

            // Update log file path (best effort - don't fail if it doesn't work)
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Update safe
            $wpdb->update(
                $table,
                array('log_file_path' => $log_file_path),
                array('backup_id' => $this->restore_id),
                array('%s'),
                array('%s')
            );
        }
    }

    /**
     * Execute restore (files first, then database as requested)
     */
    public function execute()
    {
        try {
            // Validate compression mode is selected
            $compression_mode = get_option('wpv_compression_mode', '');
            if (empty($compression_mode)) {
                $error_msg = esc_html__('Compression mode not selected. Please configure it in Settings before restoring backups.', 'wp-vault');
                $this->log->write_log($error_msg, 'error');
                $this->log_progress($error_msg, 0);
                throw new \Exception($error_msg);
            }

            // Validate compression mode availability
            require_once WP_VAULT_PLUGIN_DIR . 'includes/class-wp-vault-compression-checker.php';
            $availability = WP_Vault_Compression_Checker::get_all_availability();

            if ($compression_mode === 'fast' && !$availability['fast']['available']) {
                $error_msg = esc_html__('Fast compression mode is not available on this system. Please select Legacy mode in Settings.', 'wp-vault');
                $this->log->write_log($error_msg, 'error');
                $this->log_progress($error_msg, 0);
                throw new \Exception($error_msg);
            }

            if ($compression_mode === 'legacy' && !$availability['legacy']['available']) {
                $error_msg = esc_html__('Legacy compression mode is not available on this system. Please select Fast mode in Settings or contact your hosting provider.', 'wp-vault');
                $this->log->write_log($error_msg, 'error');
                $this->log_progress($error_msg, 0);
                throw new \Exception($error_msg);
            }

            $this->log->write_log('===== RESTORE STARTED =====', 'info');
            $this->log->write_log('Restore ID: ' . ($this->restore_id ?: 'N/A'), 'info');
            $this->log->write_log('Backup ID: ' . ($this->backup_id ?: 'N/A'), 'info');
            $this->log->write_log('Backup File: ' . basename($this->backup_file), 'info');
            $this->log->write_log('Restore Mode: ' . $this->restore_mode, 'info');
            $this->log->write_log('PHP Memory Limit: ' . ini_get('memory_limit'), 'info');
            $this->log->write_log('Max Execution Time: ' . ini_get('max_execution_time'), 'info');
            $this->log->write_log('Current Memory Usage: ' . size_format(memory_get_usage(true)), 'info');
            $this->log->write_log('Excluded tables list: ' . implode(', ', $this->excluded_tables), 'info');

            $this->log_progress('Starting restore...', 0);

            // Step 0: Pre-restore safety actions
            $this->log_php('[WP Vault] Step 0: Running pre-restore safety checks...');
            $this->log_progress('Running pre-restore safety checks...', 5);
            $this->pre_restore_safety_actions();
            $this->log_php('[WP Vault] Step 0: Pre-restore safety checks complete');

            // Step 1: Verify backup file exists or load from manifest
            $this->log_php('[WP Vault] Step 1: Loading backup files...');
            $archive_paths = array();

            // Check if we have a backup_id and should load from manifest
            if (!empty($this->backup_id) && file_exists($this->backup_dir . 'backup-' . $this->backup_id . '-manifest.json')) {
                $manifest_file = $this->backup_dir . 'backup-' . $this->backup_id . '-manifest.json';
                $this->log_php('[WP Vault] Found manifest file: ' . basename($manifest_file));
                $manifest_data = json_decode(file_get_contents($manifest_file), true);

                if ($manifest_data && isset($manifest_data['files'])) {
                    $this->log_php('[WP Vault] Manifest contains ' . count($manifest_data['files']) . ' files');
                    // Load all component files from manifest
                    foreach ($manifest_data['files'] as $file) {
                        $file_path = $this->backup_dir . $file['filename'];
                        if (file_exists($file_path)) {
                            $archive_paths[] = $file_path;
                            $this->log_php('[WP Vault] Found component file: ' . $file['filename'] . ' (' . size_format(filesize($file_path)) . ')');
                        } else {
                            $this->log_php('[WP Vault] WARNING: Component file not found: ' . $file['filename']);
                        }
                    }
                    $this->log('Found ' . count($archive_paths) . ' component files from manifest');
                } else {
                    $this->log_php('[WP Vault] WARNING: Manifest file exists but contains no files');
                }
            } else {
                $this->log_php('[WP Vault] No manifest file found, using single file mode');
            }

            // Fallback to single file
            if (empty($archive_paths)) {
                $archive_path = $this->backup_file;
                if (!file_exists($archive_path)) {
                    // Try to find in backup directory
                    $archive_path = $this->backup_dir . basename($this->backup_file);
                    if (!file_exists($archive_path)) {
                        $this->log_php('[WP Vault] ERROR: Backup file not found: ' . $this->backup_file);
                        throw new \Exception(esc_html('Backup file not found: ' . $this->backup_file));
                    }
                }
                $archive_paths = array($archive_path);
                $this->log_php('[WP Vault] Using single backup file: ' . basename($archive_path) . ' (' . size_format(filesize($archive_path)) . ')');
            }

            // Step 2: Extract all archives
            $this->log_php('[WP Vault] Step 2: Extracting ' . count($archive_paths) . ' archive file(s)...');
            $this->log_progress('Extracting backup archives...', 10);
            $extract_dir = $this->extract_all_archives($archive_paths);
            $this->log_php('[WP Vault] Step 2: Extraction complete. Files extracted to: ' . $extract_dir);

            // Step 3: Restore components based on selection
            $this->log_php('[WP Vault] Step 3: Restoring selected components...');
            $components_to_restore = isset($this->restore_options['components']) ? $this->restore_options['components'] : array('themes', 'plugins', 'uploads', 'wp-content', 'database');
            $this->log_php('[WP Vault] Components to restore: ' . implode(', ', $components_to_restore));

            $file_components = array('themes', 'plugins', 'uploads', 'wp-content');
            $has_file_components = !empty(array_intersect($components_to_restore, $file_components));
            $has_database = in_array('database', $components_to_restore);

            // Restore FILES if any file component is selected
            if ($has_file_components || $this->restore_mode === 'full' || $this->restore_mode === 'files') {
                $this->log_php('[WP Vault] Step 3a: Restoring file components...');
                $this->log_progress('Restoring files...', 30);
                $this->restore_files($extract_dir, $components_to_restore);
                $this->log_php('[WP Vault] Step 3a: File components restored');
            } else {
                $this->log_php('[WP Vault] Step 3a: Skipping file components (not selected)');
            }

            // Restore DATABASE if selected
            if ($has_database || $this->restore_mode === 'full' || $this->restore_mode === 'database') {
                $this->log_php('[WP Vault] Step 3b: Restoring database...');
                $this->log_progress('Restoring database...', 70);
                $this->restore_database($extract_dir);
                $this->log_php('[WP Vault] Step 3b: Database restored');
            } else {
                $this->log_php('[WP Vault] Step 3b: Skipping database (not selected)');
            }

            // Step 4: URL replacement if requested
            if (isset($this->restore_options['replace_urls']) && $this->restore_options['replace_urls']) {
                $this->log_php('[WP Vault] Step 4: Replacing URLs...');
                $this->log_progress('Replacing URLs...', 85);
                $this->replace_urls();
                $this->log_php('[WP Vault] Step 4: URL replacement complete');
            } else {
                $this->log_php('[WP Vault] Step 4: Skipping URL replacement (not requested)');
            }

            // Step 5: Clean up temp extraction directory only (keep original backup file)
            $this->log_php('[WP Vault] Step 5: Cleaning up temporary files...');
            $this->log_progress('Cleaning up temporary files...', 95);
            $this->cleanup(null, $extract_dir); // Don't delete the original backup file
            $this->log_php('[WP Vault] Step 5: Cleanup complete');

            $this->log->write_log('===== RESTORE COMPLETED SUCCESSFULLY =====', 'info');
            $this->log->write_log('Final Memory Usage: ' . size_format(memory_get_usage(true)), 'info');
            $this->log->write_log('Peak Memory Usage: ' . size_format(memory_get_peak_usage(true)), 'info');
            $this->log_progress('Restore complete!', 100, 'restored');

            // Close log file
            $this->log->close_file();

            return array(
                'success' => true,
                'message' => 'Restore completed successfully',
            );

        } catch (\Exception $e) {
            $this->log->write_log('===== RESTORE FAILED =====', 'error');
            $this->log->write_log('Error: ' . $e->getMessage(), 'error');
            $this->log->write_log('Stack trace: ' . $e->getTraceAsString(), 'error');
            $this->log_progress('Restore failed: ' . $e->getMessage(), 0, 'failed');

            // Close log file
            $this->log->close_file();

            return array(
                'success' => false,
                'error' => $e->getMessage(),
            );
        }
    }


    /**
     * Extract all archives (component-based backups)
     */
    private function extract_all_archives($archive_paths)
    {
        $extract_dir = $this->temp_dir . 'restore-' . time() . '/';
        wp_mkdir_p($extract_dir);
        $this->log_php('[WP Vault] Created extraction directory: ' . $extract_dir);

        $extracted_count = 0;
        foreach ($archive_paths as $index => $archive_path) {
            $this->log_php('[WP Vault] Extracting file ' . ($index + 1) . '/' . count($archive_paths) . ': ' . basename($archive_path));
            try {
                $this->extract_archive($archive_path, $extract_dir);
                $extracted_count++;
            } catch (\Exception $e) {
                $this->log_php('[WP Vault] ERROR extracting ' . basename($archive_path) . ': ' . $e->getMessage());
                throw $e;
            }
        }

        $this->log('Extracted ' . $extracted_count . ' archives to: ' . $extract_dir);
        $this->log_php('[WP Vault] Successfully extracted ' . $extracted_count . ' of ' . count($archive_paths) . ' archive(s)');
        return $extract_dir;
    }

    /**
     * Extract tar.gz archive
     */
    private function extract_archive($archive_path, $extract_dir = null)
    {
        if ($extract_dir === null) {
            $extract_dir = $this->temp_dir . 'restore-' . time() . '/';
            wp_mkdir_p($extract_dir);
        }

        $basename = basename($archive_path);
        $this->log_php('[WP Vault] Processing file: ' . $basename . ' (' . size_format(filesize($archive_path)) . ')');

        // Handle database files - check by filename pattern, not just extension
        // Database files can be: database-{id}.sql.gz, database-{id}-{date}.sql.gz, or database-{id}-{date}.gz
        // We MUST skip archives (.tar.gz, .zip) here so they go to the extraction logic below
        if (preg_match('/^database-.*\.(sql\.gz|gz)$/i', $basename) && !preg_match('/\.(tar\.gz|zip)$/i', $basename)) {
            $target_path = $extract_dir . $basename;
            $this->log_php('[WP Vault] Detected pure database file, copying (not extracting): ' . $basename);
            if (!copy($archive_path, $target_path)) {
                throw new \Exception(esc_html('Failed to copy database file: ' . $basename));
            }
            $this->log('Copied database file: ' . $basename);
            $this->log_php('[WP Vault] Database file copied successfully: ' . $basename);
            return;
        }

        try {
            // Check file extension
            $extension = pathinfo($archive_path, PATHINFO_EXTENSION);
            $is_tar_gz = substr($basename, -7) === '.tar.gz';

            $this->log_php('[WP Vault] File extension: ' . $extension . ', is_tar_gz: ' . ($is_tar_gz ? 'yes' : 'no'));

            if ($extension === 'gz' && $is_tar_gz) {
                // PharData can handle .tar.gz directly
                $this->log_php('[WP Vault] Extracting .tar.gz archive using PharData...');
                $phar = new \PharData($archive_path);
                $phar->extractTo($extract_dir, null, true);
                $this->log_php('[WP Vault] Successfully extracted .tar.gz archive');
            } elseif ($extension === 'zip') {
                // Handle ZIP files
                $this->log_php('[WP Vault] Extracting .zip archive using ZipArchive...');
                $zip = new \ZipArchive();
                if ($zip->open($archive_path) === true) {
                    $zip->extractTo($extract_dir);
                    $zip->close();
                    $this->log_php('[WP Vault] Successfully extracted .zip archive');
                } else {
                    throw new \Exception(esc_html('Failed to open ZIP archive'));
                }
            } elseif ($extension === 'gz' && !$is_tar_gz) {
                // This might be a standalone .gz file (not tar.gz)
                // Check if it's a database file (should have been caught above, but double-check)
                if (preg_match('/^database-/i', $basename)) {
                    // Database file - should have been handled above, but copy it anyway
                    $target_path = $extract_dir . $basename;
                    copy($archive_path, $target_path);
                    $this->log_php('[WP Vault] Copied standalone .gz database file');
                } else {
                    throw new \Exception(esc_html('Unsupported .gz file format (not tar.gz and not database): ' . $basename));
                }
            } else {
                throw new \Exception(esc_html('Unsupported archive format: ' . $extension . ' for file: ' . $basename));
            }

            $this->log('Extracted: ' . $basename);

        } catch (\Exception $e) {
            $this->log_php('[WP Vault] ERROR in primary extraction: ' . $e->getMessage());
            // If direct extraction fails, try decompressing first (for tar.gz)
            if ($is_tar_gz) {
                try {
                    $this->log_php('[WP Vault] Attempting fallback extraction method...');
                    $tar_path = $this->temp_dir . 'restore-' . time() . '-' . basename($archive_path, '.gz');
                    $this->decompress_gz($archive_path, $tar_path);
                    $phar = new \PharData($tar_path);
                    $phar->extractTo($extract_dir, null, true);
                    if (file_exists($tar_path)) {
                        wp_delete_file($tar_path);
                    }
                    $this->log_php('[WP Vault] Successfully extracted using fallback method');
                    $this->log('Extracted (decompressed): ' . $basename);
                } catch (\Exception $e2) {
                    $this->log_php('[WP Vault] ERROR in fallback extraction: ' . $e2->getMessage());
                    throw new \Exception(esc_html('Extraction failed for ' . $basename . ': ' . $e->getMessage() . ' / ' . $e2->getMessage()));
                }
            } else {
                throw $e;
            }
        }
    }

    /**
     * Restore database using two-phase approach (WPVivid strategy)
     */
    private function restore_database($extract_dir)
    {
        global $wpdb;

        $this->log_php('[WP Vault] Searching for database file in: ' . $extract_dir);

        // Look for database file (could be database.sql.gz, database-{id}.sql.gz, database-{id}-{date}.sql.gz, or database-{id}-{date}.gz)
        $db_file = null;
        $possible_paths = array(
            $extract_dir . 'database.sql.gz',
            $extract_dir . 'database-' . $this->backup_id . '.sql.gz',
        );

        // Also search recursively for any database file
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($extract_dir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        $this->log_php('[WP Vault] Scanning directory for database files...');
        foreach ($iterator as $file) {
            $filename = $file->getFilename();
            // Match database files: database*.sql.gz or database*.gz (but not database*.tar.gz)
            if ($file->isFile() && preg_match('/^database-.*\.(sql\.gz|gz)$/i', $filename) && !preg_match('/\.tar\.gz$/i', $filename)) {
                $db_file = $file->getPathname();
                $this->log_php('[WP Vault] Found database file: ' . $filename . ' at ' . $file->getPathname());
                break;
            }
        }

        // Try direct paths if not found recursively
        if (!$db_file) {
            $this->log_php('[WP Vault] Database file not found recursively, trying direct paths...');
            foreach ($possible_paths as $path) {
                if (file_exists($path)) {
                    $db_file = $path;
                    $this->log_php('[WP Vault] Found database file at direct path: ' . $path);
                    break;
                }
            }
        }

        if (!$db_file || !file_exists($db_file)) {
            $this->log_php('[WP Vault] ERROR: Database backup file not found in backup');
            $this->log_php('[WP Vault] Searched in: ' . $extract_dir);
            $this->log_php('[WP Vault] Files in extract directory:');
            if (is_dir($extract_dir)) {
                $files = scandir($extract_dir);
                foreach ($files as $file) {
                    if ($file !== '.' && $file !== '..') {
                        $this->log_php('[WP Vault]   - ' . $file);
                    }
                }
            }
            throw new \Exception('Database backup file not found in backup');
        }

        $this->log_php('[WP Vault] Using database file: ' . basename($db_file) . ' (' . size_format(filesize($db_file)) . ')');

        // Decompress
        $this->log_php('[WP Vault] Decompressing database file...');
        $sql_file = $extract_dir . 'database.sql';
        $this->decompress_file($db_file, $sql_file);
        $this->log_php('[WP Vault] Database file decompressed: ' . basename($sql_file) . ' (' . size_format(filesize($sql_file)) . ')');

        // Create backup of current database before restore
        $this->backup_current_database();

        // Use two-phase restore approach
        $this->log('Starting two-phase database restore...');

        try {
            // Phase 1: Import to temporary tables
            $this->log_progress('Importing database to temporary tables...', 70);
            $temp_prefix = $this->import_to_temp_tables($sql_file);

            // Phase 2: Atomic replacement
            // Note: wp_wp_vault_jobs is excluded from restore, so current job record is safe
            $this->log_progress('Replacing tables atomically...', 85);
            $this->replace_tables_atomically($temp_prefix);

            $this->log('Database restored successfully using two-phase approach');
        } catch (\Exception $e) {
            // Rollback on failure
            $this->log('Restore failed, attempting rollback...');
            $this->rollback_on_failure($temp_prefix);

            // Note: wp_wp_vault_jobs is excluded from restore, so current job record is safe
            throw $e;
        }
    }

    /**
     * Restore files (with component filtering)
     */
    private function restore_files($extract_dir, $components_to_restore = array())
    {
        $this->log_php('[WP Vault] Starting file restore...');
        $this->log_php('[WP Vault] Extract directory: ' . $extract_dir);
        $this->log_php('[WP Vault] Components to restore: ' . implode(', ', $components_to_restore));

        $restored_count = 0;

        // Component path mapping
        $component_paths = array(
            'themes' => 'wp-content/themes/',
            'plugins' => 'wp-content/plugins/',
            'uploads' => 'wp-content/uploads/',
            'wp-content' => 'wp-content/',
        );

        // If no specific components, restore all
        if (empty($components_to_restore) || in_array('files', $components_to_restore)) {
            $components_to_restore = array('themes', 'plugins', 'uploads', 'wp-content');
        }

        // Check if reset directories option is enabled
        $reset_directories = isset($this->restore_options['reset_directories']) && $this->restore_options['reset_directories'];
        $this->log_php('[WP Vault] Reset directories: ' . ($reset_directories ? 'yes' : 'no'));

        // Reset directories if requested
        if ($reset_directories) {
            $this->log_php('[WP Vault] Resetting target directories...');
            foreach ($components_to_restore as $component) {
                if (isset($component_paths[$component])) {
                    $target_dir = ABSPATH . $component_paths[$component];
                    if (is_dir($target_dir) && $component !== 'wp-content') {
                        $this->log('Resetting directory: ' . $component_paths[$component]);
                        $this->log_php('[WP Vault] Clearing directory: ' . $target_dir);
                        $this->delete_directory_contents($target_dir);
                    }
                }
            }
            $this->log_php('[WP Vault] Directory reset complete');
        }

        // Iterate through extracted files
        $this->log_php('[WP Vault] Scanning extracted files...');
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($extract_dir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        $files_scanned = 0;
        $files_skipped = 0;
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $files_scanned++;
                $filename = $file->getFilename();

                // Skip database files (they're handled separately)
                if ($filename === 'database.sql' || $filename === 'database.sql.gz' || preg_match('/^database-.*\.(sql\.gz|gz)$/i', $filename)) {
                    $files_skipped++;
                    continue;
                }

                // Normalize relative path
                $relative_path = str_replace($extract_dir, '', $file->getPathname());
                // Remove backup ID prefix if present (e.g. backup-ABC123xyz/)
                $relative_path = preg_replace('/^backup-[^\/]+\//', '', $relative_path);
                // Remove leading slashes
                $relative_path = ltrim($relative_path, '/');

                // Check if this file belongs to a selected component
                $should_restore = false;
                $matched_component = null;
                foreach ($components_to_restore as $component) {
                    if (isset($component_paths[$component])) {
                        $base_path = $component_paths[$component]; // e.g. 'wp-content/themes/'
                        $alt_path = str_replace('wp-content/', '', $base_path); // e.g. 'themes/'

                        // Check multiple path patterns to handle various tar.gz structures
                        if (
                            strpos($relative_path, $base_path) === 0 ||
                            strpos($relative_path, $alt_path) === 0 ||
                            strpos($relative_path, '/' . $base_path) !== false ||
                            strpos($relative_path, '/' . $alt_path) !== false
                        ) {
                            $should_restore = true;
                            $matched_component = $component;
                            break;
                        }
                    }
                }

                if (!$should_restore) {
                    $files_skipped++;
                    continue;
                }

                $target_path = ABSPATH . $relative_path;

                // Create directory if needed
                $target_dir = dirname($target_path);
                if (!is_dir($target_dir)) {
                    wp_mkdir_p($target_dir);
                }

                // Copy file
                if (copy($file->getPathname(), $target_path)) {
                    $restored_count++;
                    if ($restored_count % 100 === 0) {
                        $this->log_php('[WP Vault] Restored ' . $restored_count . ' files so far...');
                    }
                } else {
                    $this->log_php('[WP Vault] WARNING: Failed to copy file: ' . $relative_path);
                }
            }
        }

        $this->log_php('[WP Vault] File restore complete. Scanned: ' . $files_scanned . ', Skipped: ' . $files_skipped . ', Restored: ' . $restored_count);
        $this->log('Restored ' . $restored_count . ' files from selected components');
    }

    /**
     * Delete directory contents (but not the directory itself)
     */
    private function delete_directory_contents($dir)
    {
        if (!is_dir($dir)) {
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
    }

    /**
     * Backup current database before restore
     */
    private function backup_current_database()
    {
        $backup_file = $this->temp_dir . 'pre-restore-backup-' . time() . '.sql';

        if ($this->command_exists('mysqldump')) {
            $command = sprintf(
                'mysqldump --host=%s --user=%s --password=%s --single-transaction %s > %s 2>&1',
                escapeshellarg(DB_HOST),
                escapeshellarg(DB_USER),
                escapeshellarg(DB_PASSWORD),
                escapeshellarg(DB_NAME),
                escapeshellarg($backup_file)
            );

            exec($command);

            $this->log('Created pre-restore backup: ' . $backup_file);
        }
    }

    /**
     * Check if a table should be excluded from restore
     * 
     * @param string $table_name Table name (with or without prefix)
     * @return bool True if table should be excluded
     */
    private function is_excluded_table($table_name)
    {
        global $wpdb;
        // Remove tmp prefix if present (for temp tables)
        $table_name_clean = preg_replace('/^tmp\d+_/', '', $table_name);

        // Remove first prefix only (don't use str_replace as it removes all occurrences)
        if (strpos($table_name_clean, $wpdb->prefix) === 0) {
            $table_name_clean = substr($table_name_clean, strlen($wpdb->prefix));
        }

        // For double prefix tables like wp_wp_vault_jobs:
        // After removing first wp_, we get wp_vault_jobs
        // This should match our exclusion list which has wp_vault_jobs

        // Check multiple variations:
        // 1. The cleaned name (e.g., "vault_jobs" or "wp_vault_jobs")
        // 2. The cleaned name with wp_ prefix (e.g., "wp_vault_jobs")
        // 3. The original table name (for double prefix tables)
        // 4. Check if cleaned name ends with any excluded table name (for cases like "wpcee35evault_jobs" -> should match "wp_vault_jobs")
        $is_excluded = in_array($table_name_clean, $this->excluded_tables)
            || in_array($table_name, $this->excluded_tables)
            || in_array('wp_' . $table_name_clean, $this->excluded_tables);

        // Also check if any excluded table name is a suffix of the cleaned name
        // This handles cases where prefix removal leaves "vault_jobs" but we need to match "wp_vault_jobs"
        if (!$is_excluded) {
            foreach ($this->excluded_tables as $excluded_table) {
                // Remove "wp_" prefix from excluded table name for comparison
                $excluded_base = (strpos($excluded_table, 'wp_') === 0) ? substr($excluded_table, 3) : $excluded_table;
                // Check if cleaned name ends with the excluded base (e.g., "vault_jobs" matches "wp_vault_jobs")
                if (
                    $table_name_clean === $excluded_base || $table_name_clean === $excluded_table ||
                    (strpos($table_name_clean, $excluded_base) !== false && strpos($table_name_clean, $excluded_base) === (strlen($table_name_clean) - strlen($excluded_base)))
                ) {
                    $is_excluded = true;
                    break;
                }
            }
        }

        // Debug: log the check for vault tables
        if (stripos($table_name, 'vault') !== false || $is_excluded) {
            $this->log->write_log('is_excluded_table: input="' . $table_name . '", cleaned="' . $table_name_clean . '", prefix="' . $wpdb->prefix . '", excluded=' . ($is_excluded ? 'YES' : 'NO') . ', list=[' . implode(',', $this->excluded_tables) . ']', 'info');
        }

        return $is_excluded;
    }

    /**
     * Phase 1: Import SQL to temporary tables with chunked processing
     */
    private function import_to_temp_tables($sql_file)
    {
        global $wpdb;

        $table_prefix = $wpdb->prefix;
        $temp_prefix = $table_prefix . 'tmp' . time() . '_';
        $chunk_size = 5 * 1024 * 1024; // 5MB chunks
        $start_time = time();
        $max_execution_time = 60; // 60 seconds per chunk

        $this->log_php('[WP Vault] Starting import to temp tables with prefix: ' . $temp_prefix);
        $this->log->write_log('Import starting with temp prefix: ' . $temp_prefix, 'info');
        $this->log->write_log('Excluded tables configuration: ' . implode(', ', $this->excluded_tables), 'info');
        $this->log->write_log('Database prefix: ' . $wpdb->prefix, 'info');

        // Clean up any old temporary tables from previous restore attempts
        // This prevents "Table already exists" and "Duplicate entry" errors
        $this->cleanup_old_temp_tables($table_prefix);

        // Verify cleanup worked - check if any temp tables still exist
        $all_tables_after_cleanup = $wpdb->get_col("SHOW TABLES");
        $remaining_temp_tables = array();
        foreach ($all_tables_after_cleanup as $table) {
            if (strpos($table, $table_prefix . 'tmp') === 0 || (strpos($table, 'tmp') === 0 && strpos($table, '_wp_') !== false)) {
                $remaining_temp_tables[] = $table;
            }
        }
        if (!empty($remaining_temp_tables)) {
            $this->log_php('[WP Vault] WARNING: Found ' . count($remaining_temp_tables) . ' temp tables still remaining after cleanup: ' . implode(', ', $remaining_temp_tables));
            // Force drop them
            foreach ($remaining_temp_tables as $table) {
                $table_clean = str_replace('`', '', $table);
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is from SHOW TABLES, safe
                $wpdb->query("DROP TABLE IF EXISTS `{$table_clean}`");
                $this->log_php('[WP Vault] Force dropped remaining temp table: ' . $table_clean);
            }
        } else {
            $this->log_php('[WP Vault] Cleanup verification passed: No temp tables found');
        }

        // Get current offset from state if resuming
        $offset = 0;
        if ($this->restore_id) {
            global $wpdb;
            $table = $wpdb->prefix . 'wp_vault_jobs';
            $table_escaped = esc_sql($table);
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safe, escaped
            $job = $wpdb->get_row($wpdb->prepare(
                "SELECT current_offset FROM {$table_escaped} WHERE backup_id = %s",
                $this->restore_id
            ));
            if ($job && isset($job->current_offset)) {
                $offset = (int) $job->current_offset;
            }
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- SQL file reading requires direct file access for streaming
        $handle = fopen($sql_file, 'r');
        if (!$handle) {
            throw new \Exception(esc_html('Could not open SQL file for reading'));
        }

        // Seek to offset if resuming
        if ($offset > 0) {
            fseek($handle, $offset);
        }

        $buffer = '';
        $queries_processed = 0;
        $temp_tables = array();

        // Set permissive SQL mode for restore
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query("SET SESSION sql_mode = 'ALLOW_INVALID_DATES,NO_AUTO_VALUE_ON_ZERO'");
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query("SET FOREIGN_KEY_CHECKS=0");

        try {
            while (!feof($handle)) {
                // Check timeout
                if (time() - $start_time > $max_execution_time) {
                    // Save state for resuming
                    $offset = ftell($handle);
                    if ($this->restore_id) {
                        $this->save_state('importing', 'database', $offset);
                    }
                    $this->log('Chunk processing paused at offset: ' . $offset);
                    break;
                }

                // Read chunk
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- SQL file reading requires direct file access for streaming
                $chunk = fread($handle, $chunk_size);
                if ($chunk === false) {
                    break;
                }

                // Check if chunk is binary data
                if ($this->is_binary_data($chunk)) {
                    $this->log_php('[WP Vault] WARNING: Skipping binary data chunk at offset: ' . ftell($handle));
                    continue; // Skip this chunk
                }

                $buffer .= $chunk;

                // Extract complete queries from buffer
                $queries = $this->extract_complete_queries($buffer);

                // Process each query
                foreach ($queries as $query) {
                    $query = trim($query);
                    if (empty($query)) {
                        continue;
                    }

                    // Skip SET statements and other non-table queries
                    if (preg_match('/^(SET|USE|LOCK|UNLOCK|\/\*|\*\/)/i', $query)) {
                        continue;
                    }

                    // Extract table name from query and check if it should be excluded
                    // Try multiple patterns to catch different SQL formats
                    $table_name = null;
                    $patterns = array(
                        '/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?[`"]?(\w+)[`"]?/i',
                        '/INSERT\s+INTO\s+[`"]?(\w+)[`"]?/i',
                        '/UPDATE\s+[`"]?(\w+)[`"]?/i',
                        '/DELETE\s+FROM\s+[`"]?(\w+)[`"]?/i',
                    );

                    foreach ($patterns as $pattern) {
                        if (preg_match($pattern, $query, $matches)) {
                            $table_name = $matches[1];
                            break;
                        }
                    }

                    if ($table_name) {
                        // Remove temp prefix if present (for checking exclusion)
                        $table_name_clean = preg_replace('/^tmp\d+_/', '', $table_name);
                        // Remove wp_ prefix to check against exclusion list
                        $table_name_base = str_replace($wpdb->prefix, '', $table_name_clean);

                        // Debug logging
                        $this->log->write_log('Checking table for exclusion: original=' . $table_name . ', cleaned=' . $table_name_clean . ', base=' . $table_name_base, 'info');

                        if ($this->is_excluded_table($table_name_base)) {
                            $this->log->write_log('SKIPPING excluded table in SQL: ' . $table_name_base . ' (original: ' . $table_name . ')', 'notice');
                            continue; // Skip this query
                        } else {
                            $this->log->write_log('Table NOT excluded, will process: ' . $table_name_base, 'info');
                        }
                    }

                    // Transform table names to use temp prefix
                    $transformed_query = $this->transform_query_for_temp_tables($query, $temp_prefix, $table_name);

                    // For CREATE TABLE, drop existing temp table first to avoid conflicts
                    // Match: CREATE TABLE IF NOT EXISTS `wp_tmp123_wp_posts` or CREATE TABLE wp_tmp123_wp_posts
                    $temp_table_name = null;
                    if (preg_match('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?(?:[`"])?(' . preg_quote($temp_prefix, '/') . '[a-zA-Z0-9_]+)(?:[`"])?/i', $transformed_query, $matches)) {
                        $temp_table_name = $matches[1]; // Get table name (already without backticks from regex)
                        // Drop the table if it exists (from a previous failed restore)
                        $temp_table_name_escaped = esc_sql($temp_table_name);
                        $drop_result = $wpdb->query("DROP TABLE IF EXISTS `{$temp_table_name_escaped}`");
                        if ($drop_result !== false) {
                            $this->log_php('[WP Vault] Dropped existing temp table (if any): ' . $temp_table_name);
                        }
                    }

                    // Execute query
                    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $transformed_query is dynamically constructed SQL that cannot use placeholders
                    $result = $wpdb->query($transformed_query);

                    if ($result === false && !empty($wpdb->last_error)) {
                        // Handle "already exists" errors - this shouldn't happen if cleanup worked correctly
                        if (stripos($wpdb->last_error, 'already exists') !== false) {
                            $this->log_php('[WP Vault] WARNING: Table already exists error (should not happen): ' . $wpdb->last_error);
                            // Try to drop and retry if it's a CREATE TABLE query
                            if ($temp_table_name) {
                                $this->log_php('[WP Vault] Attempting to force drop and retry: ' . $temp_table_name);
                                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- DROP TABLE with escaped table name
                                $wpdb->query("DROP TABLE IF EXISTS `{$temp_table_name_escaped}`");
                                // Retry the query
                                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $transformed_query is dynamically constructed SQL that cannot use placeholders
                                $result = $wpdb->query($transformed_query);
                                if ($result === false && !empty($wpdb->last_error)) {
                                    $this->log_php('[WP Vault] ERROR: Retry failed: ' . $wpdb->last_error);
                                    $this->log_php('[WP Vault] Query: ' . substr($transformed_query, 0, 500));
                                } else {
                                    $this->log_php('[WP Vault] Retry succeeded for: ' . $temp_table_name);
                                }
                            }
                        }
                        // Handle "Duplicate entry" errors - INSERT IGNORE should prevent these, but log if they occur
                        else if (stripos($wpdb->last_error, 'Duplicate entry') !== false) {
                            // INSERT IGNORE should prevent this, but if it happens, log it
                            $this->log_php('[WP Vault] WARNING: Duplicate entry error (INSERT IGNORE should prevent this): ' . $wpdb->last_error);
                            $this->log_php('[WP Vault] Query: ' . substr($transformed_query, 0, 500));
                        }
                        // Log other errors
                        else {
                            $this->log_php('[WP Vault] SQL error: ' . $wpdb->last_error);
                            $this->log_php('[WP Vault] Query: ' . substr($transformed_query, 0, 500));
                        }
                    } else {
                        // Track created temp tables
                        // Match: CREATE TABLE IF NOT EXISTS `wp_tmp123_wp_posts` or CREATE TABLE wp_tmp123_wp_posts
                        if ($temp_table_name && !in_array($temp_table_name, $temp_tables)) {
                            $temp_tables[] = $temp_table_name;
                            $this->log_php('[WP Vault] Created temp table: ' . $temp_table_name);
                        }
                    }

                    $queries_processed++;
                }

                // Remove processed queries from buffer
                $buffer = $this->remove_processed_queries($buffer, $queries);
            }

            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- SQL file reading requires direct file access
            fclose($handle);

            $this->log_php('[WP Vault] Import complete. Processed ' . $queries_processed . ' queries, created ' . count($temp_tables) . ' temp tables');

            // Store temp tables list for phase 2
            if ($this->restore_id) {
                $this->save_resume_data(array('temp_tables' => $temp_tables, 'temp_prefix' => $temp_prefix));
            }

            $this->log('Imported ' . $queries_processed . ' queries to temporary tables');
            $this->log('Created ' . count($temp_tables) . ' temporary tables');
            $this->log->write_log('Import complete: processed ' . $queries_processed . ' queries, created ' . count($temp_tables) . ' temp tables', 'info');
            if (!empty($temp_tables)) {
                $this->log->write_log('Temp tables created: ' . implode(', ', array_slice($temp_tables, 0, 20)) . (count($temp_tables) > 20 ? '... (and ' . (count($temp_tables) - 20) . ' more)' : ''), 'info');
            }

            if (empty($temp_tables)) {
                $this->log_php('[WP Vault] WARNING: No temp tables were created!');
                // Try to discover tables
                $all_tables = $wpdb->get_col($wpdb->prepare("SHOW TABLES LIKE %s", $temp_prefix . '%'));
                $this->log_php('[WP Vault] Discovered ' . count($all_tables) . ' tables with prefix: ' . $temp_prefix);
                if (!empty($all_tables)) {
                    $temp_tables = $all_tables;
                }
            }

            return $temp_prefix;

        } catch (\Exception $e) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- SQL file reading requires direct file access
            fclose($handle);
            throw $e;
        }
    }

    /**
     * Phase 2: Atomically replace old tables with temp tables
     */
    private function replace_tables_atomically($temp_prefix)
    {
        global $wpdb;

        $this->log_php('[WP Vault] Starting atomic table replacement with temp prefix: ' . $temp_prefix);

        // Get temp tables from resume data or discover them
        $temp_tables = array();
        if ($this->restore_id) {
            $resume_data = $this->get_resume_data();
            if (isset($resume_data['temp_tables']) && !empty($resume_data['temp_tables'])) {
                $temp_tables = $resume_data['temp_tables'];
                $this->log_php('[WP Vault] Found ' . count($temp_tables) . ' temp tables from resume data');
            }
        }

        // If no temp tables in resume data, discover them
        if (empty($temp_tables)) {
            $all_tables = $wpdb->get_col($wpdb->prepare("SHOW TABLES LIKE %s", $temp_prefix . '%'));
            $temp_tables = $all_tables;
            $this->log_php('[WP Vault] Discovered ' . count($temp_tables) . ' temp tables from database');
            $this->log->write_log('Discovered temp tables: ' . implode(', ', $temp_tables), 'info');
        }

        if (empty($temp_tables)) {
            $this->log_php('[WP Vault] ERROR: No temporary tables found with prefix: ' . $temp_prefix);
            throw new \Exception(esc_html('No temporary tables found to replace'));
        }

        $table_prefix = $wpdb->prefix;
        $replaced_count = 0;
        $skipped_count = 0;

        $this->log->write_log('Starting atomic replacement with ' . count($temp_tables) . ' temp tables', 'info');
        $this->log->write_log('Table prefix: ' . $table_prefix, 'info');
        $this->log->write_log('Excluded tables: ' . implode(', ', $this->excluded_tables), 'info');

        foreach ($temp_tables as $temp_table) {
            // temp_table is already the full name (e.g., wp_tmp123_wp_posts)
            // Extract original table name by removing temp prefix
            // temp_prefix is like: wp_tmp123_
            // temp_table is like: wp_tmp123_wp_posts
            // We need to get: wp_posts

            if (strpos($temp_table, $temp_prefix) === 0) {
                // Remove temp prefix to get original table name
                $original_table = substr($temp_table, strlen($temp_prefix));

                // If original_table already starts with table_prefix, use it as-is
                // Otherwise, prepend table_prefix
                if (strpos($original_table, $table_prefix) !== 0) {
                    $original_table = $table_prefix . $original_table;
                }
            } else {
                // Fallback: assume temp_table is the full name and extract after temp_prefix
                $original_table = str_replace($temp_prefix, '', $temp_table);
                if (strpos($original_table, $table_prefix) !== 0) {
                    $original_table = $table_prefix . $original_table;
                }
            }

            // Skip if original table name is empty
            if (empty($original_table)) {
                $this->log_php('[WP Vault] WARNING: Empty original table name for temp table: ' . $temp_table);
                continue;
            }

            // Sanitize table names (remove any backticks and escape)
            $temp_table_clean = esc_sql(str_replace('`', '', $temp_table));
            $original_table_clean = esc_sql(str_replace('`', '', $original_table));

            // Skip excluded tables
            // Remove prefix to get base table name for comparison
            $table_name_clean = $original_table_clean;
            if (strpos($table_name_clean, $table_prefix) === 0) {
                $table_name_clean = substr($table_name_clean, strlen($table_prefix));
            }

            // Debug logging
            $this->log->write_log('Atomic replacement check: temp=' . $temp_table . ', original=' . $original_table . ', cleaned=' . $table_name_clean, 'info');

            if ($this->is_excluded_table($table_name_clean)) {
                $this->log->write_log('SKIPPING excluded table during atomic replacement: ' . $table_name_clean . ' (temp: ' . $temp_table . ', original: ' . $original_table . ')', 'notice');
                // Drop the temp table since we're not using it
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared -- DROP TABLE cannot use placeholders, table name is escaped with esc_sql()
                $wpdb->query("DROP TABLE IF EXISTS `{$temp_table_clean}`");
                $skipped_count++;
                continue;
            } else {
                $this->log->write_log('Table NOT excluded, will replace: ' . $table_name_clean, 'info');
            }

            $this->log_php('[WP Vault] Replacing: ' . $temp_table . ' -> ' . $original_table);

            // Truly atomic approach: Swap current table to _old and temp table to current in ONE command
            // This prevents the table from "disappearing" even for a millisecond
            $old_table_clean = esc_sql($original_table_clean . '_old_' . time());

            // Note: RENAME TABLE doesn't support prepared statements, table names are escaped with esc_sql()
            $query = "RENAME TABLE `{$original_table_clean}` TO `{$old_table_clean}`, `{$temp_table_clean}` TO `{$original_table_clean}`";

            // If the original table doesn't exist (e.g., first restore), just rename temp to original
            $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $original_table_clean));
            if (!$table_exists) {
                $query = "RENAME TABLE `{$temp_table_clean}` TO `{$original_table_clean}`";
            }

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared -- RENAME TABLE cannot use placeholders, table names are escaped with esc_sql()
            $result = $wpdb->query($query);

            if ($result === false) {
                $this->log_php('[WP Vault] ERROR: Failed to swap table ' . $temp_table . ' to ' . $original_table . ': ' . $wpdb->last_error);
            } else {
                // Drop the old table now that it's been swapped out
                if ($table_exists) {
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared -- DROP TABLE cannot use placeholders, table name is escaped with esc_sql()
                    $wpdb->query("DROP TABLE IF EXISTS `{$old_table_clean}`");
                }
                $replaced_count++;
                $this->log_php('[WP Vault] Successfully replaced: ' . $original_table);
            }
        }

        // Re-enable foreign key checks
        $wpdb->query("SET FOREIGN_KEY_CHECKS=1");

        $this->log_php('[WP Vault] Atomically replaced ' . $replaced_count . ' of ' . count($temp_tables) . ' tables (skipped ' . $skipped_count . ' excluded tables)');
        $this->log->write_log('Atomic replacement complete: replaced=' . $replaced_count . ', skipped=' . $skipped_count . ', total=' . count($temp_tables), 'info');
        $this->log('Atomically replaced ' . $replaced_count . ' tables');
    }

    /**
     * Rollback on failure - remove temp tables
     */
    private function rollback_on_failure($temp_prefix)
    {
        global $wpdb;

        if (empty($temp_prefix)) {
            return;
        }

        $temp_tables = $wpdb->get_col("SHOW TABLES LIKE '{$temp_prefix}%'");

        foreach ($temp_tables as $table) {
            $table_escaped = esc_sql($table);
            $wpdb->query("DROP TABLE IF EXISTS `{$table_escaped}`");
        }

        $this->log('Rolled back: removed ' . count($temp_tables) . ' temporary tables');
    }

    /**
     * Clean up old temporary tables from previous restore attempts
     * 
     * This method finds and drops ALL temporary tables that could interfere with restore:
     * 1. Tables matching current pattern: wp_tmp{timestamp}_wp_tablename
     * 2. Tables matching old problematic pattern: tmp{timestamp}_wp_tablename
     * 
     * Based on WPvivid's approach: they use a simpler prefix (tmp{uid}_) and clean up aggressively
     */
    private function cleanup_old_temp_tables($table_prefix)
    {
        global $wpdb;

        $this->log_php('[WP Vault] Cleaning up old temporary tables...');

        // Get ALL tables in the database (more comprehensive than LIKE pattern)
        $all_tables = $wpdb->get_col("SHOW TABLES");

        $old_temp_tables_to_drop = array();
        $current_temp_prefix_pattern = $table_prefix . 'tmp'; // e.g., wp_tmp

        foreach ($all_tables as $table) {
            // Pattern 1: Tables created by the current (fixed) logic
            // e.g., wp_tmp1765518762_wp_posts
            if (strpos($table, $current_temp_prefix_pattern) === 0) {
                $old_temp_tables_to_drop[] = $table;
            }
            // Pattern 2: Tables from old/buggy restore attempts
            // e.g., tmp1765518762_wp_posts (observed in error logs)
            // These start with 'tmp' directly and contain '_wp_' to avoid false positives
            else if (strpos($table, 'tmp') === 0 && strpos($table, '_wp_') !== false) {
                $old_temp_tables_to_drop[] = $table;
            }
        }

        // Remove duplicates
        $old_temp_tables_to_drop = array_unique($old_temp_tables_to_drop);

        if (!empty($old_temp_tables_to_drop)) {
            $this->log_php('[WP Vault] Found ' . count($old_temp_tables_to_drop) . ' old temporary tables to clean up');
            foreach ($old_temp_tables_to_drop as $table) {
                // Sanitize table name (remove backticks if present)
                $table_clean = str_replace('`', '', $table);
                $wpdb->query("DROP TABLE IF EXISTS `{$table_clean}`");
                $this->log_php('[WP Vault] Dropped old temp table: ' . $table_clean);
            }
            $this->log_php('[WP Vault] Cleaned up ' . count($old_temp_tables_to_drop) . ' old temporary tables');
        } else {
            $this->log_php('[WP Vault] No old temporary tables found matching known patterns');
        }
    }

    /**
     * Transform SQL query to use temp prefix for table names
     */
    private function transform_query_for_temp_tables($query, $temp_prefix, $table_name = null)
    {
        // If we have a detected table name, use it specifically for replacement
        if ($table_name) {
            $escaped_table = preg_quote($table_name, '/');
            // Support optional backticks and handle the table name replacement specifically
            // This is more robust as it doesn't rely on matching the current site's prefix
            $query = preg_replace('/([`"]?)' . $escaped_table . '([`"]?)/', '$1' . $temp_prefix . $table_name . '$2', $query);
        } else {
            // Fallback to prefix-based replacement if no table was detected
            $table_prefix = $GLOBALS['wpdb']->prefix;
            $escaped_prefix = preg_quote($table_prefix, '/');
            $query = preg_replace('/([`"]?)(' . $escaped_prefix . ')([a-zA-Z0-9_]+)([`"]?)/i', '$1' . $temp_prefix . '$2$3$4', $query);
        }

        // Ensure safety flags are added regardless of prefix matching
        // Add IGNORE to INSERT/REPLACE statements to prevent duplicate entry errors
        if (preg_match('/^(INSERT|REPLACE)\s+/i', trim($query))) {
            if (stripos($query, 'IGNORE') === false) {
                $query = preg_replace('/^(INSERT|REPLACE)(\s+INTO)/i', '$1 IGNORE$2', trim($query));
            }
        }

        // Add IF NOT EXISTS to CREATE TABLE statements
        if (stripos($query, 'CREATE TABLE') !== false && stripos($query, 'IF NOT EXISTS') === false) {
            $query = preg_replace('/(CREATE\s+TABLE\s+)/i', '$1IF NOT EXISTS ', $query);
        }

        return $query;
    }

    /**
     * Extract complete SQL queries from buffer
     */
    private function extract_complete_queries($buffer)
    {
        $queries = array();
        $current_query = '';
        $in_string = false;
        $string_char = '';
        $in_comment = false;

        for ($i = 0; $i < strlen($buffer); $i++) {
            $char = $buffer[$i];
            $next_char = isset($buffer[$i + 1]) ? $buffer[$i + 1] : '';

            // Handle comments
            if (!$in_string && !$in_comment && $char === '/' && $next_char === '*') {
                $in_comment = true;
                $i++;
                continue;
            }

            if ($in_comment && $char === '*' && $next_char === '/') {
                $in_comment = false;
                $i++;
                continue;
            }

            if ($in_comment) {
                continue;
            }

            // Handle strings
            if (($char === '"' || $char === "'") && ($i === 0 || $buffer[$i - 1] !== '\\')) {
                if (!$in_string) {
                    $in_string = true;
                    $string_char = $char;
                } elseif ($char === $string_char) {
                    $in_string = false;
                    $string_char = '';
                }
            }

            $current_query .= $char;

            // Check for query end (semicolon outside of string)
            if (!$in_string && $char === ';') {
                $queries[] = $current_query;
                $current_query = '';
            }
        }

        // Return remaining buffer as incomplete query (will be processed in next chunk)
        return $queries;
    }

    /**
     * Remove processed queries from buffer
     */
    private function remove_processed_queries($buffer, $queries)
    {
        foreach ($queries as $query) {
            $pos = strpos($buffer, $query);
            if ($pos !== false) {
                $buffer = substr($buffer, $pos + strlen($query));
            }
        }

        return trim($buffer);
    }

    /**
     * Save state for resuming
     */
    private function save_state($step, $component, $offset)
    {
        if (!$this->restore_id) {
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'wp_vault_jobs';

        $wpdb->update(
            $table,
            array(
                'current_step' => $step,
                'current_component' => $component,
                'current_offset' => $offset,
                'updated_at' => current_time('mysql'),
            ),
            array('backup_id' => $this->restore_id),
            array('%s', '%s', '%d', '%s'),
            array('%s')
        );
    }

    /**
     * Save resume data
     */
    private function save_resume_data($data)
    {
        if (!$this->restore_id) {
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'wp_vault_jobs';

        $wpdb->update(
            $table,
            array(
                'resume_data' => json_encode($data),
                'updated_at' => current_time('mysql'),
            ),
            array('backup_id' => $this->restore_id),
            array('%s', '%s'),
            array('%s')
        );
    }

    /**
     * Get resume data
     */
    private function get_resume_data()
    {
        if (!$this->restore_id) {
            return array();
        }

        global $wpdb;
        $table = $wpdb->prefix . 'wp_vault_jobs';
        $table_escaped = esc_sql($table);

        $job = $wpdb->get_row($wpdb->prepare(
            "SELECT resume_data FROM {$table_escaped} WHERE backup_id = %s",
            $this->restore_id
        ));

        if ($job && !empty($job->resume_data)) {
            return json_decode($job->resume_data, true);
        }

        return array();
    }

    /**
     * Pre-restore safety actions (WPVivid strategy)
     */
    private function pre_restore_safety_actions()
    {
        // 1. Validate database connection
        global $wpdb;
        $wpdb->get_var('SELECT 1');
        if (!empty($wpdb->last_error)) {
            throw new \Exception(esc_html('Database connection validation failed: ' . $wpdb->last_error));
        }

        // 2. Check disk space (need at least 500MB free)
        $free_space = disk_free_space(ABSPATH);
        if ($free_space !== false && $free_space < 500 * 1024 * 1024) {
            $this->log('WARNING: Low disk space: ' . size_format($free_space));
        }

        // 3. Deactivate plugins if requested
        if (isset($this->restore_options['deactivate_plugins']) && $this->restore_options['deactivate_plugins']) {
            $this->deactivate_plugins();
        }

        // 4. Switch to default theme if requested
        if (isset($this->restore_options['switch_theme']) && $this->restore_options['switch_theme']) {
            $this->switch_to_default_theme();
        }

        // 5. Create pre-restore backup (already done in restore_database, but log it)
        $this->log('Pre-restore safety checks completed');
    }

    /**
     * Deactivate all plugins except WP-Vault
     */
    private function deactivate_plugins()
    {
        $active_plugins = get_option('active_plugins', array());
        $wp_vault_plugin = 'wp-vault/wp-vault.php'; // Plugin basename

        // Store list for reactivation
        if ($this->restore_id) {
            $this->save_resume_data(array('active_plugins' => $active_plugins));
        }

        // Deactivate all except WP-Vault
        foreach ($active_plugins as $plugin) {
            if (strpos($plugin, 'wp-vault') === false) {
                deactivate_plugins($plugin);
            }
        }

        $this->log('Deactivated plugins (except WP-Vault)');
    }

    /**
     * Switch to default theme
     */
    private function switch_to_default_theme()
    {
        $current_theme = get_option('stylesheet');
        $default_theme = 'twentytwentythree'; // WordPress default

        // Store current theme for restoration
        if ($this->restore_id) {
            $resume_data = $this->get_resume_data();
            $resume_data['previous_theme'] = $current_theme;
            $this->save_resume_data($resume_data);
        }

        // Switch theme
        switch_theme($default_theme);
        $this->log('Switched to default theme');
    }

    /**
     * Replace URLs in database
     */
    private function replace_urls()
    {
        require_once WP_VAULT_PLUGIN_DIR . 'includes/class-wp-vault-url-replacer.php';

        // Detect old URLs from backup
        $old_urls = \WP_Vault\WP_Vault_URL_Replacer::detect_old_urls();
        $old_url = $old_urls['site_url'] ?: $old_urls['home_url'];

        // Get new URL (current site)
        $new_url = get_site_url();

        if (empty($old_url) || $old_url === $new_url) {
            $this->log('No URL replacement needed');
            return;
        }

        $this->log('Replacing URLs: ' . $old_url . ' → ' . $new_url);

        $replacer = new \WP_Vault\WP_Vault_URL_Replacer($old_url, $new_url);
        $replaced_count = $replacer->replace_in_database();

        $this->log('Replaced URLs in ' . $replaced_count . ' database rows');
    }

    /**
     * Decompress gzip file
     */
    private function decompress_file($source, $destination)
    {
        // Check if source file exists and is readable
        if (!file_exists($source) || !is_readable($source)) {
            throw new \Exception('Database file not found or not readable: ' . esc_html($source));
        }

        // Check if it's actually a gzip file (check magic number 0x1f 0x8b)
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Binary file operation for detecting gzip magic number
        $handle = fopen($source, 'rb');
        if ($handle) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Binary file operation for detecting gzip magic number
            $header = fread($handle, 2);
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Binary file operation for detecting gzip magic number
            fclose($handle);

            if (strlen($header) !== 2 || ord($header[0]) !== 0x1f || ord($header[1]) !== 0x8b) {
                // Not a gzip file - might already be decompressed
                if (copy($source, $destination)) {
                    $this->log_php('[WP Vault] Database file is not gzipped, copied directly');
                    return;
                } else {
                    throw new \Exception('Failed to copy database file (not gzipped)');
                }
            }
        }

        $fp_in = gzopen($source, 'rb');
        if (!$fp_in) {
            // Try alternative decompression method
            $this->log_php('[WP Vault] gzopen failed, trying alternative method...');
            $content = file_get_contents('compress.zlib://' . $source);
            if ($content === false) {
                throw new \Exception('Failed to decompress database file');
            }
            file_put_contents($destination, $content);
            $this->log_php('[WP Vault] Database file decompressed using alternative method');
            return;
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Binary decompression requires direct file access
        $fp_out = fopen($destination, 'wb');
        if (!$fp_out) {
            gzclose($fp_in);
            throw new \Exception('Failed to open destination file for writing: ' . esc_html($destination));
        }

        $bytes_written = 0;
        while (!gzeof($fp_in)) {
            $data = gzread($fp_in, 8192);
            if ($data === false) {
                gzclose($fp_in);
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Binary decompression requires direct file access
                fclose($fp_out);
                throw new \Exception('Error reading from gzip file: ' . esc_html($source));
            }
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Binary decompression requires direct file access
            $written = fwrite($fp_out, $data);
            if ($written === false) {
                gzclose($fp_in);
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Binary decompression requires direct file access
                fclose($fp_out);
                throw new \Exception('Error writing decompressed data to: ' . esc_html($destination));
            }
            $bytes_written += $written;
        }

        gzclose($fp_in);
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Binary decompression requires direct file access
        fclose($fp_out);

        // Validate decompressed file is text (SQL)
        if ($bytes_written > 0) {
            $this->validate_sql_file($destination);
        }
    }

    /**
     * Validate SQL file format
     */
    private function validate_sql_file($sql_file)
    {
        if (!file_exists($sql_file))
            return;

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- SQL file validation requires direct file access
        $handle = fopen($sql_file, 'r');
        if (!$handle)
            return;

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- SQL file validation requires direct file access
        $sample = fread($handle, 1024);
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- SQL file validation requires direct file access
        fclose($handle);

        // Check if it contains SQL keywords
        $sql_keywords = array('CREATE', 'INSERT', 'UPDATE', 'DELETE', 'DROP', 'ALTER', 'TABLE');
        $has_sql = false;
        foreach ($sql_keywords as $keyword) {
            if (stripos($sample, $keyword) !== false) {
                $has_sql = true;
                break;
            }
        }

        // Check if it's mostly printable text (not binary)
        $printable = 0;
        $total = strlen($sample);
        for ($i = 0; $i < $total; $i++) {
            $char = ord($sample[$i]);
            if (($char >= 32 && $char <= 126) || $char === 9 || $char === 10 || $char === 13) {
                $printable++;
            }
        }
        $printable_ratio = $total > 0 ? $printable / $total : 0;

        if (!$has_sql || $printable_ratio < 0.8) {
            $this->log_php('[WP Vault] WARNING: Decompressed file may not be valid SQL');
            $this->log_php('[WP Vault] SQL keywords found: ' . ($has_sql ? 'yes' : 'no'));
            $this->log_php('[WP Vault] Printable character ratio: ' . round($printable_ratio * 100, 2) . '%');
        } else {
            $this->log_php('[WP Vault] SQL validation passed (ratio: ' . round($printable_ratio * 100, 2) . '%)');
        }
    }

    /**
     * Detect if data is binary
     */
    private function is_binary_data($data)
    {
        if (empty($data)) {
            return false;
        }

        $sample_size = min(512, strlen($data));
        $sample = substr($data, 0, $sample_size);

        // Count printable characters
        $printable = 0;
        for ($i = 0; $i < $sample_size; $i++) {
            $char = ord($sample[$i]);
            if (($char >= 32 && $char <= 126) || $char === 9 || $char === 10 || $char === 13) {
                $printable++;
            }
        }

        $ratio = $printable / $sample_size;

        // If less than 70% printable, consider it binary
        return $ratio < 0.7;
    }

    /**
     * Decompress .tar.gz to .tar
     */
    private function decompress_gz($source, $destination)
    {
        $fp_in = gzopen($source, 'rb');
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Binary decompression requires direct file access
        $fp_out = fopen($destination, 'wb');

        while (!gzeof($fp_in)) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Binary decompression requires direct file access
            fwrite($fp_out, gzread($fp_in, 8192));
        }

        gzclose($fp_in);
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Binary decompression requires direct file access
        fclose($fp_out);
    }

    /**
     * Get storage configuration
     */
    private function get_storage_config($type)
    {
        switch ($type) {
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
     * Check if command exists
     */
    private function command_exists($command)
    {
        $path = exec("which $command 2>/dev/null");
        return !empty($path);
    }

    /**
     * Clean up temporary files (extraction directory only, keep original backup)
     */
    private function cleanup($archive_path, $extract_dir)
    {
        // Only delete temp archive if it's in temp_dir (downloaded copy), not the original backup
        if ($archive_path && file_exists($archive_path) && strpos($archive_path, $this->temp_dir) === 0) {
            wp_delete_file($archive_path);
        }

        if (is_dir($extract_dir)) {
            $this->delete_directory($extract_dir);
        }
    }

    /**
     * Recursively delete directory
     */
    private function delete_directory($dir)
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), array('.', '..'));

        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->delete_directory($path) : wp_delete_file($path);
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
     * Log progress to database and error log
     */
    private function log_progress($message, $percent, $status = 'running')
    {
        $this->log($message . ' (' . $percent . '%)');

        // Save to database if restore_id is set
        if ($this->restore_id) {
            global $wpdb;
            $table = $wpdb->prefix . 'wp_vault_jobs';
            $table_escaped = esc_sql($table);
            $logs_table = $wpdb->prefix . 'wp_vault_job_logs';

            // Debug: Check if job exists before update
            $existing_job = $wpdb->get_row($wpdb->prepare("SELECT backup_id, status FROM {$table_escaped} WHERE backup_id = %s", $this->restore_id));
            if ($existing_job) {
                $this->log_php('[WP Vault] log_progress: Found job with backup_id: ' . $existing_job->backup_id . ', current status: ' . $existing_job->status);
            } else {
                $this->log_php('[WP Vault] log_progress: WARNING - Job not found with backup_id: ' . $this->restore_id);
                // Try to find any restore jobs
                $all_restores = $wpdb->get_results($wpdb->prepare("SELECT backup_id, status FROM {$table_escaped} WHERE job_type = %s ORDER BY started_at DESC LIMIT 5", 'restore'));
                $this->log_php('[WP Vault] log_progress: Found ' . count($all_restores) . ' restore jobs in database');
                foreach ($all_restores as $job) {
                    $this->log_php('[WP Vault] log_progress:   - ' . $job->backup_id . ' (status: ' . $job->status . ')');
                }
            }

            // Update job status
            // If job doesn't exist, try to create it (shouldn't happen, but handle gracefully)
            if (!$existing_job) {
                $this->log_php('[WP Vault] WARNING: Job not found, attempting to create it...');
                $insert_result = $wpdb->insert(
                    $table,
                    array(
                        'backup_id' => $this->restore_id,
                        'job_type' => 'restore',
                        'status' => $status,
                        'progress_percent' => $percent,
                        'started_at' => current_time('mysql'),
                        'updated_at' => current_time('mysql'),
                    ),
                    array('%s', '%s', '%s', '%d', '%s', '%s')
                );
                if ($insert_result === false) {
                    $this->log_php('[WP Vault] ERROR: Failed to create restore job. Error: ' . $wpdb->last_error);
                } else {
                    $this->log_php('[WP Vault] Created restore job: ' . $this->restore_id);
                }
            } else {
                // Normal update path
                $update_data = array(
                    'status' => $status,
                    'progress_percent' => $percent,
                    'updated_at' => current_time('mysql'),
                );
                $format = array('%s', '%d', '%s');

                // Set finished_at for terminal statuses
                if ($status === 'restored' || $status === 'completed' || $status === 'failed' || $status === 'cancelled') {
                    $update_data['finished_at'] = current_time('mysql');
                    $format[] = '%s';
                }

                $update_result = $wpdb->update(
                    $table,
                    $update_data,
                    array('backup_id' => $this->restore_id),
                    $format,
                    array('%s')
                );

                // Log if update failed (for debugging)
                if ($update_result === false && !empty($wpdb->last_error)) {
                    $this->log_php('[WP Vault] WARNING: Failed to update restore status in log_progress. Error: ' . $wpdb->last_error);
                } else if ($status === 'restored' || $status === 'completed' || $status === 'failed') {
                    $this->log_php('[WP Vault] log_progress: Updated restore status to: ' . $status . ' (rows affected: ' . ($update_result !== false ? $update_result : 0) . ', restore_id: ' . $this->restore_id . ')');
                }
            }

            // Log to file instead of database
            if ($this->log) {
                $log_level = ($status === 'error' || $status === 'failed') ? 'error' : (($status === 'completed') ? 'notice' : 'info');
                $this->log->write_log($message . ' (' . $percent . '%)', $log_level);
            }
        }
    }

    /**
     * Simple logger
     */
    private function log($message)
    {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log('[WP Vault Restore] ' . $message);
        }
    }

    /**
     * Log PHP message (detailed logging for debugging)
     * Similar to backup engine's log_php method
     */
    private function log_php($message)
    {
        $timestamp = gmdate('Y-m-d H:i:s');
        if (defined('WP_DEBUG') && WP_DEBUG) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log('[' . $timestamp . '] [WP Vault] ' . $message);
        }

        // Also write to file log if available
        if ($this->log) {
            // Remove [WP Vault] prefix if present for cleaner logs
            $clean_message = preg_replace('/^\[WP Vault\]\s*/', '', $message);
            $level = (stripos($message, 'ERROR') !== false || stripos($message, 'FAILED') !== false) ? 'error' :
                ((stripos($message, 'WARNING') !== false) ? 'warning' : 'info');
            $this->log->write_log($clean_message, $level);
        }
    }

    /**
     * Execute incremental restore from snapshot
     * 
     * @param string $snapshot_id Snapshot ID to restore
     * @return bool Success
     */
    public function execute_incremental($snapshot_id)
    {
        try {
            $this->log->write_log('===== INCREMENTAL RESTORE STARTED =====', 'info');
            $this->log->write_log('Snapshot ID: ' . $snapshot_id, 'info');
            $this->log->write_log('Restore Mode: ' . $this->restore_mode, 'info');

            $this->log_progress('Requesting restore plan...', 5);

            // Get restore plan from cloud
            $api = new WP_Vault_API();
            $plan_result = $api->get_restore_plan($snapshot_id, $this->restore_mode);

            if (!$plan_result['success']) {
                throw new \Exception(esc_html('Failed to get restore plan: ' . ($plan_result['error'] ?? 'Unknown error')));
            }

            $restore_steps = $plan_result['data']['restore_steps'];
            $this->log->write_log('Restore plan received: ' . count($restore_steps) . ' steps', 'info');

            // Execute steps in order
            $step_count = count($restore_steps);
            foreach ($restore_steps as $index => $step) {
                $step_num = $index + 1;
                $progress = 10 + (($step_num / $step_count) * 80); // 10-90%

                $this->log->write_log("Executing step $step_num/$step_count: {$step['type']} snapshot {$step['snapshot_id']}", 'info');
                $this->log_progress("Restoring step $step_num/$step_count...", $progress);

                // Download and extract each component
                foreach ($step['download_urls'] as $component => $download_url) {
                    $this->log->write_log("Downloading component: $component", 'info');

                    // Download file
                    $temp_file = $this->temp_dir . 'restore-' . time() . '-' . $component . '.tar.gz';
                    $download_result = $this->download_file($download_url, $temp_file);

                    if (!$download_result) {
                        throw new \Exception(esc_html("Failed to download component: $component"));
                    }

                    // Extract and restore
                    if ($component === 'database') {
                        $this->restore_database_from_file($temp_file);
                    } else {
                        $this->restore_files_from_archive($temp_file, $component);
                    }

                    // Clean up
                    wp_delete_file($temp_file);
                }
            }

            $this->log_progress('Restore complete', 100, 'restored');
            $this->log->write_log('===== INCREMENTAL RESTORE COMPLETE =====', 'info');

            return true;
        } catch (\Exception $e) {
            $this->log->write_log('Restore failed: ' . $e->getMessage(), 'error');
            $this->log_progress('Restore failed', 0);
            throw $e;
        }
    }

    /**
     * Download file from URL
     */
    private function download_file($url, $destination)
    {
        $response = wp_remote_get($url, [
            'timeout' => 300,
            'stream' => true,
            'filename' => $destination
        ]);

        if (is_wp_error($response)) {
            $this->log->write_log('Download failed: ' . $response->get_error_message(), 'error');
            return false;
        }

        $status_code = wp_remote_retrieve_response_code($response);
        if ($status_code !== 200) {
            $this->log->write_log("Download failed with status: $status_code", 'error');
            return false;
        }

        return file_exists($destination);
    }

    /**
     * Restore database from file
     */
    private function restore_database_from_file($sql_file)
    {
        $extract_dir = $this->temp_dir . 'restore-' . time() . '/';
        wp_mkdir_p($extract_dir);

        // Decompress if needed
        if (substr($sql_file, -3) === '.gz') {
            $decompressed = $extract_dir . 'database.sql';
            $this->decompress_gz($sql_file, $decompressed);
            $sql_file = $decompressed;
        }

        // Import to temp tables
        $this->import_to_temp_tables($sql_file);

        // Replace atomically
        $temp_prefix = 'tmp' . time() . '_';
        $this->replace_tables_atomically($temp_prefix);
    }

    /**
     * Restore files from archive
     */
    private function restore_files_from_archive($archive_path, $component)
    {
        $extract_dir = $this->temp_dir . 'restore-' . time() . '-' . $component . '/';
        wp_mkdir_p($extract_dir);

        // Extract archive
        $this->extract_archive($archive_path, $extract_dir);

        // Restore files
        $this->restore_files($extract_dir, [$component]);
    }
}
