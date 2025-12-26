<?php
/**
 * Incremental Uploader
 * 
 * Handles incremental backup uploads based on cloud plan
 *
 * @package WP_Vault
 * @since 1.0.0
 */

namespace WP_Vault;

class WP_Vault_Incremental_Uploader
{
    private $backup_id;
    private $api;
    private $log;

    public function __construct($backup_id, $log = null)
    {
        $this->backup_id = $backup_id;
        $this->api = new WP_Vault_API();
        $this->log = $log;
    }

    /**
     * Execute incremental backup based on plan
     * 
     * @param array $plan Incremental plan from cloud
     * @return array Result
     */
    public function execute($plan)
    {
        if (!$this->log) {
            $this->log = new WP_Vault_Log();
            $this->log->create_log_file($this->backup_id, 'backup');
        }

        $this->log->write_log('Starting incremental backup execution', 'info');
        $this->log->write_log('Snapshot ID: ' . $plan['snapshot_id'], 'info');
        $this->log->write_log('Snapshot Type: ' . $plan['snapshot_type'], 'info');
        $this->log->write_log('Files to upload: ' . count($plan['upload_required']), 'info');

        // Package and upload files
        $uploaded_objects = [];

        // If no files to upload, skip file packaging (incremental with no changes)
        if (empty($plan['upload_required'])) {
            $this->log->write_log('No files to upload (all files unchanged)', 'info');
        } else {
            // Group files by component
            $components = $this->group_files_by_component($plan['upload_required']);

            foreach ($components as $component => $files) {
                if (empty($files)) {
                    continue;
                }

                $this->log->write_log("Packaging component: $component", 'info');

                // Create archive for component
                $archive_path = $this->package_component($component, $files);

                if ($archive_path) {
                    // Upload to GCS using signed URL
                    $object_key = $this->upload_component($component, $archive_path, $plan);

                    if ($object_key) {
                        $uploaded_objects[] = [
                            'component' => $component,
                            'key' => $object_key,
                            'checksum' => hash_file('sha256', $archive_path),
                            'size' => filesize($archive_path)
                        ];

                        // Clean up local archive
                        @unlink($archive_path);
                    }
                }
            }
        }

        // Package and upload database (always full dump for MVP)
        $this->log->write_log('Packaging database', 'info');
        $db_archive = $this->package_database();

        if ($db_archive) {
            $db_object_key = $this->upload_component('database', $db_archive, $plan);

            if ($db_object_key) {
                $uploaded_objects[] = [
                    'component' => 'database',
                    'key' => $db_object_key,
                    'checksum' => hash_file('sha256', $db_archive),
                    'size' => filesize($db_archive)
                ];

                @unlink($db_archive);
            }
        }

        // Commit snapshot (even if no files uploaded - this is valid for incremental with no changes)
        $result = $this->api->commit_snapshot($plan['snapshot_id'], $uploaded_objects);

        if ($result['success']) {
            $this->log->write_log('Snapshot committed successfully', 'info');
            // Include uploaded objects in response for size calculation
            $result['data']['objects'] = $uploaded_objects;
        } else {
            $this->log->write_log('Failed to commit snapshot: ' . ($result['error'] ?? 'Unknown error'), 'error');
        }

        return $result;
    }

    /**
     * Group files by component
     */
    private function group_files_by_component($files)
    {
        $components = [
            'themes' => [],
            'plugins' => [],
            'uploads' => [],
            'wp-content' => []
        ];

        foreach ($files as $file_path) {
            if (strpos($file_path, 'themes/') === 0) {
                $components['themes'][] = $file_path;
            } elseif (strpos($file_path, 'plugins/') === 0) {
                $components['plugins'][] = $file_path;
            } elseif (strpos($file_path, 'uploads/') === 0) {
                $components['uploads'][] = $file_path;
            } else {
                $components['wp-content'][] = $file_path;
            }
        }

        return $components;
    }

    /**
     * Package component files into archive
     */
    private function package_component($component, $files)
    {
        $backup_dir = WP_CONTENT_DIR . '/wp-vault-backups/';
        if (!is_dir($backup_dir)) {
            wp_mkdir_p($backup_dir);
        }

        // Create tar file first, then compress to .tar.gz
        $archive_name_tar = $component . '-' . $this->backup_id . '-' . date('Y-m-d-His') . '.tar';
        $archive_name_gz = $archive_name_tar . '.gz';
        $archive_path_tar = $backup_dir . $archive_name_tar;
        $archive_path_gz = $backup_dir . $archive_name_gz;

        // Remove any existing files with these names
        if (file_exists($archive_path_tar)) {
            @unlink($archive_path_tar);
        }
        if (file_exists($archive_path_gz)) {
            @unlink($archive_path_gz);
        }

        // Use PharData for tar.gz creation
        try {
            // Create tar file
            $phar = new \PharData($archive_path_tar);

            foreach ($files as $file_path) {
                $full_path = WP_CONTENT_DIR . '/' . ltrim($file_path, '/');
                if (file_exists($full_path)) {
                    $phar->addFile($full_path, $file_path);
                }
            }

            // Compress to .tar.gz (this creates a new file)
            $phar->compress(\Phar::GZ);

            // Remove uncompressed tar file
            if (file_exists($archive_path_tar)) {
                @unlink($archive_path_tar);
            }

            // Verify the compressed file exists
            if (file_exists($archive_path_gz)) {
                return $archive_path_gz;
            } else {
                throw new \Exception('Compressed archive file was not created');
            }
        } catch (\Exception $e) {
            $this->log->write_log('Failed to package component: ' . $e->getMessage(), 'error');
            // Clean up on error
            if (file_exists($archive_path_tar)) {
                @unlink($archive_path_tar);
            }
            if (file_exists($archive_path_gz)) {
                @unlink($archive_path_gz);
            }
            return false;
        }
    }

    /**
     * Package database
     */
    private function package_database()
    {
        $this->log->write_log('Starting database export...', 'info');

        $backup_dir = WP_CONTENT_DIR . '/wp-vault-backups/';
        if (!is_dir($backup_dir)) {
            wp_mkdir_p($backup_dir);
        }

        $db_file = $backup_dir . 'database-' . $this->backup_id . '-' . date('Y-m-d-His') . '.sql';
        $db_file_gz = $db_file . '.gz';

        global $wpdb;

        // Try mysqldump first
        $db_name = DB_NAME;
        $db_user = DB_USER;
        $db_pass = DB_PASSWORD;
        $db_host = DB_HOST;

        if (function_exists('exec')) {
            $command = "mysqldump --single-transaction --quick --lock-tables=false --host={$db_host} --user={$db_user} --password={$db_pass} {$db_name} 2>&1";
            exec($command, $output, $return_var);

            if ($return_var === 0 && !empty($output)) {
                file_put_contents($db_file, implode("\n", $output));

                // Compress
                $gz = gzopen($db_file_gz, 'w9');
                gzwrite($gz, file_get_contents($db_file));
                gzclose($gz);
                @unlink($db_file);

                if (file_exists($db_file_gz)) {
                    $this->log->write_log('Database exported using mysqldump', 'info');
                    return $db_file_gz;
                }
            }
        }

        // Fallback to WordPress-based export
        $this->log->write_log('Using WordPress-based database export...', 'info');
        $tables = $wpdb->get_col('SHOW TABLES');
        $sql = '';

        // Excluded tables
        $excluded_tables = [
            'wp_wp_vault_jobs',
            'wp_wp_vault_job_logs',
            'wp_wp_vault_file_index',
        ];

        foreach ($tables as $table) {
            // Skip excluded tables
            $table_clean = str_replace($wpdb->prefix, '', $table);
            if (in_array($table_clean, $excluded_tables) || in_array($table, $excluded_tables)) {
                continue;
            }

            // Get table structure
            $create_table = $wpdb->get_row("SHOW CREATE TABLE `{$table}`", ARRAY_N);
            if ($create_table) {
                $sql .= "\n\n-- Table structure for `{$table}`\n";
                $sql .= "DROP TABLE IF EXISTS `{$table}`;\n";
                $sql .= $create_table[1] . ";\n\n";
            }

            // Get table data
            $rows = $wpdb->get_results("SELECT * FROM `{$table}`", ARRAY_A);
            if (!empty($rows)) {
                $sql .= "-- Data for table `{$table}`\n";
                foreach ($rows as $row) {
                    $values = array_map(function ($value) use ($wpdb) {
                        return $wpdb->_real_escape($value);
                    }, array_values($row));
                    $sql .= "INSERT INTO `{$table}` VALUES ('" . implode("','", $values) . "');\n";
                }
            }
        }

        // Write SQL file
        file_put_contents($db_file, $sql);

        // Compress
        $gz = gzopen($db_file_gz, 'w9');
        gzwrite($gz, file_get_contents($db_file));
        gzclose($gz);
        @unlink($db_file);

        if (file_exists($db_file_gz)) {
            $this->log->write_log('Database exported successfully', 'info');
            return $db_file_gz;
        }

        $this->log->write_log('Database export failed', 'error');
        return false;
    }

    /**
     * Upload component to GCS
     */
    private function upload_component($component, $archive_path, $plan)
    {
        // Get signed URL from plan
        $upload_url = $plan['upload_urls'][$component] ?? null;

        if (!$upload_url) {
            $this->log->write_log("No upload URL for component: $component", 'error');
            return false;
        }

        // Upload file using signed URL
        $file_content = file_get_contents($archive_path);
        $file_size = filesize($archive_path);

        $this->log->write_log("Uploading $component ($file_size bytes) to GCS...", 'info');

        $response = wp_remote_request($upload_url, [
            'method' => 'PUT',
            'body' => $file_content,
            'headers' => [
                'Content-Type' => 'application/gzip',
                'Content-Length' => $file_size
            ],
            'timeout' => 300
        ]);

        if (is_wp_error($response)) {
            $this->log->write_log('Upload failed: ' . $response->get_error_message(), 'error');
            return false;
        }

        $status_code = wp_remote_retrieve_response_code($response);
        if ($status_code !== 200 && $status_code !== 204) {
            $response_body = wp_remote_retrieve_body($response);
            $this->log->write_log("Upload failed with status: $status_code. Response: " . substr($response_body, 0, 200), 'error');
            return false;
        }

        // Extract object key from signed URL
        // GCS signed URL format: https://storage.googleapis.com/bucket-name/path/to/file?signature...
        // We need to extract just the path (without bucket name)
        $parsed_url = parse_url($upload_url);
        $full_path = ltrim($parsed_url['path'], '/');

        // Remove query parameters
        if (strpos($full_path, '?') !== false) {
            $full_path = substr($full_path, 0, strpos($full_path, '?'));
        }

        // The path includes bucket name, extract just the object key
        // Format: bucket-name/snapshots/tenant/site/snapshot/component.tar.gz
        // We want: snapshots/tenant/site/snapshot/component.tar.gz
        $parts = explode('/', $full_path, 2);
        $object_key = count($parts) > 1 ? $parts[1] : $full_path;

        $this->log->write_log("Uploaded component: $component to $object_key", 'info');
        return $object_key;
    }
}
