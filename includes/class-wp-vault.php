<?php
/**
 * Main Plugin Class
 * 
 * @package WP_Vault
 * @since 1.0.0
 */

namespace WP_Vault;

class WP_Vault
{
    /**
     * Plugin instance
     */
    private static $instance = null;

    /**
     * Get plugin instance
     */
    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Initialize plugin
     */
    public function run()
    {
        // Ensure database tables exist
        $this->ensure_tables_exist();

        // Load text domain
        add_action('init', array($this, 'load_textdomain'));

        // Admin menu and pages
        if (is_admin()) {
            add_action('admin_menu', array($this, 'add_admin_menu'));
            add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        }

        // Register AJAX handlers
        add_action('wp_ajax_wpv_test_connection', array($this, 'ajax_test_connection'));
        add_action('wp_ajax_wpv_trigger_backup', array($this, 'ajax_trigger_backup'));
        add_action('wp_ajax_wpv_get_backup_status', array($this, 'ajax_get_backup_status')); // Listener for progress polling
        add_action('wp_ajax_wpv_get_backups', array($this, 'ajax_get_backups'));
        add_action('wp_ajax_wpv_restore_backup', array($this, 'ajax_restore_backup'));
        add_action('wp_ajax_wpv_get_restore_status', array($this, 'ajax_get_restore_status'));
        add_action('wp_ajax_wpv_delete_backup', array($this, 'ajax_delete_backup'));
        add_action('wp_ajax_wpv_save_storage_preference', array($this, 'ajax_save_storage_preference'));
        add_action('wp_ajax_wpv_make_primary_storage', array($this, 'ajax_make_primary_storage'));
        add_action('wp_ajax_wpv_cleanup_temp_files', array($this, 'ajax_cleanup_temp_files'));
        add_action('wp_ajax_wpv_download_backup', array($this, 'ajax_download_backup'));
        add_action('wp_ajax_wpv_download_backup_file', array($this, 'ajax_download_backup_file'));
        add_action('wp_ajax_wpv_read_log', array($this, 'ajax_read_log'));
        add_action('wp_ajax_wpv_download_log', array($this, 'ajax_download_log'));

        // Backup execution (async)
        add_action('wpv_execute_backup', array($this, 'execute_backup'), 10, 2);

        // Heartbeat (runs every 6 hours)
        if (!wp_next_scheduled('wpv_heartbeat')) {
            wp_schedule_event(time(), 'twicedaily', 'wpv_heartbeat');
        }
        add_action('wpv_heartbeat', array($this, 'send_heartbeat'));
    }

    /**
     * Ensure database tables exist (runs on every page load)
     */
    private function ensure_tables_exist()
    {
        global $wpdb;

        // Check and add updated_at column to wp_wp_vault_jobs if missing
        $table_jobs = $wpdb->prefix . 'wp_vault_jobs';
        $updated_at_exists = $wpdb->get_results("SHOW COLUMNS FROM $table_jobs LIKE 'updated_at'");
        if (empty($updated_at_exists)) {
            $wpdb->query("ALTER TABLE $table_jobs ADD COLUMN updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at");
        }

        // Check and add log_file_path column to wp_wp_vault_jobs if missing
        $log_file_path_exists = $wpdb->get_results("SHOW COLUMNS FROM $table_jobs LIKE 'log_file_path'");
        if (empty($log_file_path_exists)) {
            $wpdb->query("ALTER TABLE $table_jobs ADD COLUMN log_file_path varchar(255) DEFAULT NULL AFTER error_message");
        }

        // Drop wp_vault_job_logs table (replaced by file-based logging)
        // This is a migration - table is no longer needed
        $table_job_logs = $wpdb->prefix . 'wp_vault_job_logs';
        $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_job_logs));
        if ($table_exists) {
            $wpdb->query("DROP TABLE IF EXISTS $table_job_logs");
        }

        // Check and add total_size_bytes and updated_at columns if missing
        $table_jobs = $wpdb->prefix . 'wp_vault_jobs';
        $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_jobs));

        if ($table_exists) {
            // Add total_size_bytes column if missing
            $column_exists = $wpdb->get_results($wpdb->prepare("SHOW COLUMNS FROM $table_jobs LIKE %s", 'total_size_bytes'));
            if (empty($column_exists)) {
                $wpdb->query("ALTER TABLE $table_jobs ADD COLUMN total_size_bytes bigint(20) DEFAULT 0 AFTER progress_percent");
            }

            // Add updated_at column if missing
            $updated_at_exists = $wpdb->get_results($wpdb->prepare("SHOW COLUMNS FROM $table_jobs LIKE %s", 'updated_at'));
            if (empty($updated_at_exists)) {
                $wpdb->query("ALTER TABLE $table_jobs ADD COLUMN updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at");
            }

            // Add new columns for chunked processing and resume functionality
            $columns_to_add = array(
                'compression_mode' => "ALTER TABLE $table_jobs ADD COLUMN compression_mode VARCHAR(20) DEFAULT 'fast' AFTER job_type",
                'current_step' => "ALTER TABLE $table_jobs ADD COLUMN current_step VARCHAR(50) AFTER status",
                'current_component' => "ALTER TABLE $table_jobs ADD COLUMN current_component VARCHAR(50) AFTER current_step",
                'current_offset' => "ALTER TABLE $table_jobs ADD COLUMN current_offset BIGINT DEFAULT 0 AFTER current_component",
                'resume_data' => "ALTER TABLE $table_jobs ADD COLUMN resume_data TEXT AFTER current_offset",
                'components' => "ALTER TABLE $table_jobs ADD COLUMN components TEXT AFTER resume_data",
            );

            foreach ($columns_to_add as $column_name => $sql) {
                $column_exists = $wpdb->get_results($wpdb->prepare("SHOW COLUMNS FROM $table_jobs LIKE %s", $column_name));
                if (empty($column_exists)) {
                    $wpdb->query($sql);
                }
            }
        }
    }

    /**
     * Load text domain
     */
    public function load_textdomain()
    {
        load_plugin_textdomain('wp-vault', false, dirname(plugin_basename(WP_VAULT_PLUGIN_FILE)) . '/languages');
    }

    /**
     * Add admin menu
     */
    public function add_admin_menu()
    {
        add_menu_page(
            __('WP Vault', 'wp-vault'),
            __('WP Vault', 'wp-vault'),
            'manage_options',
            'wp-vault',
            array($this, 'render_dashboard_page'),
            'dashicons-cloud-upload',
            30
        );

        add_submenu_page(
            'wp-vault',
            __('Dashboard', 'wp-vault'),
            __('Dashboard', 'wp-vault'),
            'manage_options',
            'wp-vault',
            array($this, 'render_dashboard_page')
        );

        add_submenu_page(
            'wp-vault',
            __('Backups', 'wp-vault'),
            __('Backups', 'wp-vault'),
            'manage_options',
            'wp-vault-backups',
            array($this, 'render_backups_page')
        );

        add_submenu_page(
            'wp-vault',
            __('Restores', 'wp-vault'),
            __('Restores', 'wp-vault'),
            'manage_options',
            'wp-vault-restores',
            array($this, 'render_restores_page')
        );

        add_submenu_page(
            'wp-vault',
            __('Settings', 'wp-vault'),
            __('Settings', 'wp-vault'),
            'manage_options',
            'wp-vault-settings',
            array($this, 'render_settings_page')
        );
    }

    /**
     * Enqueue admin assets
     */
    public function enqueue_admin_assets($hook)
    {
        if (strpos($hook, 'wp-vault') === false) {
            return;
        }

        wp_enqueue_style('wp-vault-admin', WP_VAULT_PLUGIN_URL . 'assets/css/admin.css', array(), WP_VAULT_VERSION);
        wp_enqueue_script('wp-vault-admin', WP_VAULT_PLUGIN_URL . 'assets/js/admin.js', array('jquery'), WP_VAULT_VERSION, true);

        wp_localize_script('wp-vault-admin', 'wpVault', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wp-vault'),
            'i18n' => array(
                'testing' => __('Testing connection...', 'wp-vault'),
                'success' => __('Connection successful!', 'wp-vault'),
                'failed' => __('Connection failed', 'wp-vault'),
            ),
        ));
    }

    /**
     * Render dashboard page
     */
    public function render_dashboard_page()
    {
        require_once WP_VAULT_PLUGIN_DIR . 'admin/dashboard.php';
    }

    /**
     * Render settings page
     */
    public function render_backups_page()
    {
        require_once WP_VAULT_PLUGIN_DIR . 'admin/backups.php';
    }

    public function render_restores_page()
    {
        require_once WP_VAULT_PLUGIN_DIR . 'admin/restores.php';
    }

    public function render_settings_page()
    {
        require_once WP_VAULT_PLUGIN_DIR . 'admin/settings.php';
    }

    /**
     * AJAX: Test storage connection
     */
    public function ajax_test_connection()
    {
        check_ajax_referer('wp-vault', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }

        $storage_type = sanitize_text_field($_POST['storage_type']);
        $config = isset($_POST['config']) ? $_POST['config'] : array();

        try {
            require_once WP_VAULT_PLUGIN_DIR . 'includes/storage/class-storage-factory.php';

            // For GCS (WP Vault Cloud), use API endpoint and site token from options
            if ($storage_type === 'gcs') {
                $config = array(
                    'api_endpoint' => get_option('wpv_api_endpoint', 'http://host.docker.internal:3000'),
                    'site_token' => get_option('wpv_site_token', ''),
                );
            }

            $storage = \WP_Vault\Storage\Storage_Factory::create($storage_type, $config);
            $result = $storage->test_connection();

            wp_send_json_success($result);
        } catch (\Exception $e) {
            wp_send_json_error(array('message' => $e->getMessage()));
        }
    }

    /**
     * AJAX: Trigger backup
     */
    public function ajax_trigger_backup()
    {
        check_ajax_referer('wp-vault', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }

        $backup_type = isset($_POST['backup_type']) ? sanitize_text_field($_POST['backup_type']) : 'full';

        require_once WP_VAULT_PLUGIN_DIR . 'includes/class-wp-vault-api.php';
        $api = new WP_Vault_API();

        // Create backup job in SaaS
        $result = $api->create_backup($backup_type, 'manual');

        if ($result['success']) {
            $backup_id = $result['data']['backup_id'];

            // Create local job record for tracking
            global $wpdb;
            $table = $wpdb->prefix . 'wp_vault_jobs';
            $wpdb->insert(
                $table,
                array(
                    'backup_id' => $backup_id,
                    'job_type' => $backup_type,
                    'status' => 'pending',
                    'progress_percent' => 0,
                    'started_at' => current_time('mysql'),
                ),
                array('%s', '%s', '%s', '%d', '%s')
            );

            // Execute backup immediately in background (spawn process)
            // This avoids waiting for WP-Cron which only runs on page load
            spawn_cron();

            // Also schedule for WP-Cron as fallback
            wp_schedule_single_event(time(), 'wpv_execute_backup', array($backup_id, $backup_type));

            // Trigger immediately via action hook
            do_action('wpv_execute_backup', $backup_id, $backup_type);

            wp_send_json_success(array(
                'message' => 'Backup started',
                'backup_id' => $backup_id,
            ));
        } else {
            wp_send_json_error($result);
        }
    }

    /**
     * AJAX: Get backups list
     */
    public function ajax_get_backups()
    {
        check_ajax_referer('wp-vault', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }

        require_once WP_VAULT_PLUGIN_DIR . 'includes/class-wp-vault-api.php';
        $api = new WP_Vault_API();

        $result = $api->get_backups();

        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }

    /**
     * Send heartbeat to SaaS
     */
    public function send_heartbeat()
    {
        require_once WP_VAULT_PLUGIN_DIR . 'includes/class-wp-vault-api.php';
        $api = new WP_Vault_API();
        $api->send_heartbeat();
    }

    /**
     * Execute backup (async)
     */
    public function execute_backup($backup_id, $backup_type)
    {
        // Set execution time and memory limits for background process
        @set_time_limit(0);
        @ini_set('max_execution_time', 0);
        @ini_set('memory_limit', '512M');

        error_log('[WP Vault] execute_backup called - Backup ID: ' . $backup_id . ', Type: ' . $backup_type);

        require_once WP_VAULT_PLUGIN_DIR . 'includes/class-wp-vault-backup-engine.php';

        try {
            $engine = new WP_Vault_Backup_Engine($backup_id, $backup_type);
            $result = $engine->execute();

            // Update SaaS with result
            if ($result['success']) {
                error_log('[WP Vault] Backup completed: ' . $backup_id);
            } else {
                error_log('[WP Vault] Backup failed: ' . $result['error']);
            }
        } catch (\Exception $e) {
            error_log('[WP Vault] Backup exception: ' . $e->getMessage());
            error_log('[WP Vault] Exception trace: ' . $e->getTraceAsString());
        }
    }
    /**
     * Get backup progress
     */
    public function ajax_get_backup_status()
    {
        check_ajax_referer('wp-vault', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }

        $backup_id = isset($_POST['backup_id']) ? sanitize_text_field($_POST['backup_id']) : '';

        global $wpdb;
        $table = $wpdb->prefix . 'wp_vault_jobs';

        // Check if log_file_path column exists before querying
        $column_exists = $wpdb->get_results("SHOW COLUMNS FROM $table LIKE 'log_file_path'");
        $log_file_path_select = empty($column_exists) ? '' : ', log_file_path';

        $job = $wpdb->get_row($wpdb->prepare("SELECT status, progress_percent, error_message, total_size_bytes{$log_file_path_select} FROM $table WHERE backup_id = %s", $backup_id));

        // If job doesn't exist locally, create a pending record (might be a race condition)
        if (!$job) {
            $wpdb->insert(
                $table,
                array(
                    'backup_id' => $backup_id,
                    'job_type' => 'full',
                    'status' => 'pending',
                    'progress_percent' => 0,
                    'started_at' => current_time('mysql'),
                ),
                array('%s', '%s', '%s', '%d', '%s')
            );
            $job = $wpdb->get_row($wpdb->prepare("SELECT status, progress_percent, error_message, total_size_bytes{$log_file_path_select} FROM $table WHERE backup_id = %s", $backup_id));
        }

        // Read logs from file if available
        $logs = array();
        if ($job && isset($job->log_file_path) && !empty($job->log_file_path) && file_exists($job->log_file_path)) {
            require_once WP_VAULT_PLUGIN_DIR . 'includes/class-wp-vault-log.php';
            $log_result = WP_Vault_Log::read_log($job->log_file_path, -100); // Last 100 lines
            if (!empty($log_result['content'])) {
                $log_lines = explode("\n", trim($log_result['content']));
                foreach ($log_lines as $line) {
                    if (empty(trim($line)))
                        continue;
                    // Parse log line: [timestamp][level] message
                    if (preg_match('/^\[([^\]]+)\]\[([^\]]+)\]\s*(.+)$/', $line, $matches)) {
                        $logs[] = array(
                            'severity' => strtoupper($matches[2]),
                            'message' => $matches[3],
                            'created_at' => $matches[1],
                        );
                    }
                }
            }
        }

        // Fallback to SaaS logs if file log not available
        if (empty($logs)) {
            require_once WP_VAULT_PLUGIN_DIR . 'includes/class-wp-vault-api.php';
            $api = new WP_Vault_API();
            $logs_response = $api->get_backup_logs($backup_id, 50);
            $logs = $logs_response['success'] ? $logs_response['logs'] : array();
        }

        if ($job) {
            wp_send_json_success(array(
                'status' => $job->status,
                'progress' => (int) $job->progress_percent,
                'message' => $job->error_message,
                'size_bytes' => isset($job->total_size_bytes) ? (int) $job->total_size_bytes : 0,
                'log_file_path' => isset($job->log_file_path) ? $job->log_file_path : null,
                'logs' => $logs
            ));
        } else {
            wp_send_json_error(array('message' => 'Job not found'));
        }
    }

    /**
     * Restore backup AJAX handler
     */
    public function ajax_restore_backup()
    {
        check_ajax_referer('wp-vault', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }

        $backup_id = isset($_POST['backup_id']) ? sanitize_text_field($_POST['backup_id']) : '';
        $backup_file = isset($_POST['backup_file']) ? sanitize_text_field($_POST['backup_file']) : '';
        $backup_path = isset($_POST['backup_path']) ? sanitize_text_field($_POST['backup_path']) : '';
        $restore_mode = isset($_POST['restore_mode']) ? sanitize_text_field($_POST['restore_mode']) : 'full';

        // If backup_id is provided, use manifest; otherwise use backup_file
        if (!empty($backup_id)) {
            $backup_dir = WP_CONTENT_DIR . '/wp-vault-backups/';
            $manifest_file = $backup_dir . 'backup-' . $backup_id . '-manifest.json';

            if (file_exists($manifest_file)) {
                // Use first component file for restore path (restore engine will load all from manifest)
                $manifest_data = json_decode(file_get_contents($manifest_file), true);
                if ($manifest_data && !empty($manifest_data['files'])) {
                    $backup_path = $backup_dir . $manifest_data['files'][0]['filename'];
                    $backup_file = $manifest_data['files'][0]['filename'];
                }
            }
        }

        if (empty($backup_file) || empty($backup_path)) {
            wp_send_json_error(array('error' => 'Backup file not specified'));
        }

        // Verify file exists
        if (!file_exists($backup_path)) {
            wp_send_json_error(array('error' => 'Backup file not found'));
        }

        // Get components and restore options
        $components = isset($_POST['components']) && is_array($_POST['components'])
            ? array_map('sanitize_text_field', $_POST['components'])
            : array('files', 'database');

        $restore_options = array();
        if (isset($_POST['restore_options']) && is_array($_POST['restore_options'])) {
            foreach ($_POST['restore_options'] as $key => $value) {
                $restore_options[sanitize_text_field($key)] = (bool) $value;
            }
        }

        // Map components to restore options format
        $restore_options['components'] = $components;

        // Create restore job record
        global $wpdb;
        $restore_id = 'restore-' . uniqid();
        $table = $wpdb->prefix . 'wp_vault_jobs';

        $restore_options['backup_id'] = $backup_id; // Store original backup_id for manifest lookup

        $wpdb->insert(
            $table,
            array(
                'backup_id' => $restore_id,
                'job_type' => 'restore',
                'status' => 'running',
                'progress_percent' => 0,
                'resume_data' => json_encode($restore_options),
                'started_at' => current_time('mysql'),
            ),
            array('%s', '%s', '%s', '%d', '%s', '%s')
        );

        // Trigger restore in background (using WP-Cron)
        add_action('wpv_execute_restore', array($this, 'execute_restore'), 10, 3);
        wp_schedule_single_event(time(), 'wpv_execute_restore', array($restore_id, $backup_path, $restore_options));

        // Spawn cron immediately
        spawn_cron();
        do_action('wpv_execute_restore', $restore_id, $backup_path, $restore_options);

        wp_send_json_success(array(
            'restore_id' => $restore_id,
            'message' => 'Restore started'
        ));
    }

    /**
     * Get restore status AJAX handler
     */
    public function ajax_get_restore_status()
    {
        check_ajax_referer('wp-vault', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }

        $restore_id = isset($_POST['restore_id']) ? sanitize_text_field($_POST['restore_id']) : '';

        global $wpdb;
        $table = $wpdb->prefix . 'wp_vault_jobs';

        // Debug: Log the query we're about to run
        error_log('[WP Vault] AJAX Status Check - Querying for restore_id: ' . $restore_id);

        // Check if log_file_path column exists before querying
        $column_exists = $wpdb->get_results("SHOW COLUMNS FROM $table LIKE 'log_file_path'");
        $log_file_path_select = empty($column_exists) ? '' : ', log_file_path';

        $job = $wpdb->get_row($wpdb->prepare("SELECT status, progress_percent, error_message{$log_file_path_select} FROM $table WHERE backup_id = %s", $restore_id));

        if (!$job) {
            error_log('[WP Vault] AJAX Status Check - Job not found! Searching for restore_id: ' . $restore_id);
            // Try to find any restore jobs
            $all_restores = $wpdb->get_results($wpdb->prepare("SELECT backup_id, status FROM $table WHERE job_type = %s ORDER BY started_at DESC LIMIT 5", 'restore'));
            error_log('[WP Vault] AJAX Status Check - Found ' . count($all_restores) . ' restore jobs in database');
            foreach ($all_restores as $restore_job) {
                error_log('[WP Vault] AJAX Status Check -   - ' . $restore_job->backup_id . ' (status: ' . $restore_job->status . ')');
            }
            wp_send_json_error(array('message' => 'Restore job not found'));
        }

        // Debug logging
        error_log('[WP Vault] AJAX Status Check - Found job! Restore ID: ' . $restore_id . ', Status: ' . $job->status . ', Progress: ' . $job->progress_percent);

        // Read logs from file if available
        $logs = array();
        if (!empty($job->log_file_path) && file_exists($job->log_file_path)) {
            require_once WP_VAULT_PLUGIN_DIR . 'includes/class-wp-vault-log.php';
            $log_result = WP_Vault_Log::read_log($job->log_file_path, -100); // Last 100 lines
            if (!empty($log_result['content'])) {
                $log_lines = explode("\n", trim($log_result['content']));
                foreach ($log_lines as $line) {
                    if (empty(trim($line)))
                        continue;
                    // Parse log line: [timestamp][level] message
                    if (preg_match('/^\[([^\]]+)\]\[([^\]]+)\]\s*(.+)$/', $line, $matches)) {
                        $logs[] = array(
                            'severity' => strtoupper($matches[2]),
                            'message' => $matches[3],
                            'created_at' => $matches[1],
                        );
                    }
                }
            }
        }

        // Fallback to database logs if file log not available (for backward compatibility)
        if (empty($logs)) {
            $logs_table = $wpdb->prefix . 'wp_vault_job_logs';
            $db_logs = $wpdb->get_results($wpdb->prepare(
                "SELECT level, message, percent, created_at FROM $logs_table WHERE backup_id = %s ORDER BY created_at ASC",
                $restore_id
            ));
            $logs = $db_logs ? array_map(function ($log) {
                return array(
                    'severity' => $log->level,
                    'message' => $log->message,
                    'percent' => (int) $log->percent,
                    'created_at' => $log->created_at,
                );
            }, $db_logs) : array();
        }

        wp_send_json_success(array(
            'status' => $job->status,
            'progress' => (int) $job->progress_percent,
            'message' => $job->error_message,
            'log_file_path' => $job->log_file_path,
            'logs' => $logs,
        ));
    }

    /**
     * Delete backup AJAX handler (deletes all components)
     */
    public function ajax_delete_backup()
    {
        check_ajax_referer('wp-vault', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }

        $backup_id = isset($_POST['backup_id']) ? sanitize_text_field($_POST['backup_id']) : '';
        $backup_file = isset($_POST['backup_file']) ? sanitize_text_field($_POST['backup_file']) : '';

        $backup_dir = WP_CONTENT_DIR . '/wp-vault-backups/';
        $deleted_count = 0;

        if (!empty($backup_id)) {
            // Delete by backup_id (all components)
            $manifest_file = $backup_dir . 'backup-' . $backup_id . '-manifest.json';

            if (file_exists($manifest_file)) {
                $manifest_data = json_decode(file_get_contents($manifest_file), true);
                if ($manifest_data && isset($manifest_data['files'])) {
                    // Delete all component files
                    foreach ($manifest_data['files'] as $file) {
                        $file_path = $backup_dir . $file['filename'];
                        if (file_exists($file_path)) {
                            @unlink($file_path);
                            $deleted_count++;
                        }
                    }
                }
                // Delete manifest
                @unlink($manifest_file);
                $deleted_count++;
            } else {
                // No manifest, delete all files matching backup_id pattern
                $pattern = $backup_dir . '*-' . $backup_id . '-*.tar.gz';
                $files = glob($pattern);
                foreach ($files as $file) {
                    @unlink($file);
                    $deleted_count++;
                }
            }
        } elseif (!empty($backup_file)) {
            // Legacy: delete single file
            $backup_path = $backup_dir . $backup_file;

            // Security: ensure file is in backup directory
            if (strpos(realpath($backup_path), realpath($backup_dir)) !== 0) {
                wp_send_json_error(array('error' => 'Invalid backup file'));
            }

            if (!file_exists($backup_path)) {
                wp_send_json_error(array('error' => 'Backup file not found'));
            }

            if (@unlink($backup_path)) {
                $deleted_count = 1;
            }
        } else {
            wp_send_json_error(array('error' => 'Backup ID or file not specified'));
        }

        if ($deleted_count > 0) {
            wp_send_json_success(array('message' => sprintf(__('Deleted %d file(s)', 'wp-vault'), $deleted_count)));
        } else {
            wp_send_json_error(array('error' => 'Failed to delete backup files'));
        }
    }

    /**
     * Download backup (all components as ZIP)
     */
    public function ajax_download_backup()
    {
        check_ajax_referer('wp-vault', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        $backup_id = isset($_GET['backup_id']) ? sanitize_text_field($_GET['backup_id']) : '';

        if (empty($backup_id)) {
            wp_die('Backup ID not specified');
        }

        $backup_dir = WP_CONTENT_DIR . '/wp-vault-backups/';
        $manifest_file = $backup_dir . 'backup-' . $backup_id . '-manifest.json';

        if (!file_exists($manifest_file)) {
            wp_die('Backup manifest not found');
        }

        $manifest_data = json_decode(file_get_contents($manifest_file), true);
        if (!$manifest_data || empty($manifest_data['files'])) {
            wp_die('Invalid backup manifest');
        }

        // Create ZIP archive with all components
        $zip_filename = 'backup-' . $backup_id . '-' . date('Y-m-d-His') . '.zip';
        $zip_path = $backup_dir . $zip_filename;

        // Remove existing ZIP if any
        if (file_exists($zip_path)) {
            @unlink($zip_path);
        }

        // Use ZipArchive if available, otherwise use PclZip
        if (class_exists('ZipArchive')) {
            $zip = new \ZipArchive();
            if ($zip->open($zip_path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                wp_die('Failed to create ZIP archive');
            }

            // Add all component files to ZIP
            foreach ($manifest_data['files'] as $file) {
                $file_path = $backup_dir . $file['filename'];
                if (file_exists($file_path)) {
                    $zip->addFile($file_path, $file['filename']);
                }
            }

            // Add manifest
            $zip->addFile($manifest_file, basename($manifest_file));
            $zip->close();
        } else {
            // Fallback to PclZip
            require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';
            $zip = new \PclZip($zip_path);

            // Add all component files to ZIP
            foreach ($manifest_data['files'] as $file) {
                $file_path = $backup_dir . $file['filename'];
                if (file_exists($file_path)) {
                    $zip->add($file_path, PCLZIP_OPT_REMOVE_PATH, $backup_dir);
                }
            }

            // Add manifest
            $zip->add($manifest_file, PCLZIP_OPT_REMOVE_PATH, $backup_dir);
        }

        if (!file_exists($zip_path)) {
            wp_die('Failed to create ZIP archive');
        }

        // Send file for download
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $zip_filename . '"');
        header('Content-Length: ' . filesize($zip_path));
        header('Pragma: no-cache');
        header('Expires: 0');

        readfile($zip_path);

        // Clean up ZIP file after download
        @unlink($zip_path);
        exit;
    }

    /**
     * Download single backup file
     */
    public function ajax_download_backup_file()
    {
        check_ajax_referer('wp-vault', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        $filename = isset($_GET['file']) ? sanitize_file_name($_GET['file']) : '';

        if (empty($filename)) {
            wp_die('File not specified');
        }

        $backup_dir = WP_CONTENT_DIR . '/wp-vault-backups/';
        $file_path = $backup_dir . $filename;

        // Security: ensure file is in backup directory
        if (strpos(realpath($file_path), realpath($backup_dir)) !== 0) {
            wp_die('Invalid file path');
        }

        if (!file_exists($file_path)) {
            wp_die('File not found');
        }

        // Send file for download
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($file_path));
        header('Pragma: no-cache');
        header('Expires: 0');

        readfile($file_path);
        exit;
    }

    /**
     * Execute restore (called by cron)
     */
    public function execute_restore($restore_id, $backup_path, $restore_options = array())
    {
        require_once WP_VAULT_PLUGIN_DIR . 'includes/class-wp-vault-restore-engine.php';

        // Determine restore mode from options
        $restore_mode = 'full';
        if (isset($restore_options['components'])) {
            $components = $restore_options['components'];
            if (count($components) === 1) {
                if (in_array('database', $components)) {
                    $restore_mode = 'database';
                } else {
                    $restore_mode = 'files';
                }
            }
        }

        $engine = new WP_Vault_Restore_Engine($backup_path, $restore_mode, $restore_id, $restore_options);
        $result = $engine->execute();

        // Update job status - ensure it's set to completed/failed
        global $wpdb;
        $table = $wpdb->prefix . 'wp_vault_jobs';
        $final_status = $result['success'] ? 'completed' : 'failed';
        $final_progress = $result['success'] ? 100 : 0;

        // Debug: Check if job exists before update
        $existing_job = $wpdb->get_row($wpdb->prepare("SELECT backup_id, status, job_type FROM $table WHERE backup_id = %s", $restore_id));
        if ($existing_job) {
            error_log('[WP Vault] execute_restore: Found job with backup_id: ' . $existing_job->backup_id . ', current status: ' . $existing_job->status . ', job_type: ' . $existing_job->job_type);
        } else {
            error_log('[WP Vault] execute_restore: WARNING - Job not found with backup_id: ' . $restore_id);
            // Try to find any restore jobs
            $all_restores = $wpdb->get_results($wpdb->prepare("SELECT backup_id, status FROM $table WHERE job_type = %s ORDER BY started_at DESC LIMIT 5", 'restore'));
            error_log('[WP Vault] execute_restore: Found ' . count($all_restores) . ' restore jobs in database');
            foreach ($all_restores as $job) {
                error_log('[WP Vault] execute_restore:   - ' . $job->backup_id . ' (status: ' . $job->status . ')');
            }
        }

        $update_result = $wpdb->update(
            $table,
            array(
                'status' => $final_status,
                'progress_percent' => $final_progress,
                'error_message' => $result['success'] ? null : $result['error'],
                'finished_at' => current_time('mysql'),
            ),
            array('backup_id' => $restore_id),
            array('%s', '%d', '%s', '%s'),
            array('%s')
        );

        // Verify the update worked
        if ($update_result === false) {
            error_log('[WP Vault] ERROR: Failed to update restore status in database. Last error: ' . $wpdb->last_error);
        } else {
            error_log('[WP Vault] execute_restore: Updated restore status to: ' . $final_status . ' (rows affected: ' . $update_result . ', restore_id: ' . $restore_id . ')');
        }

        // Double-check the status was actually saved
        $verify_job = $wpdb->get_row($wpdb->prepare("SELECT status, progress_percent FROM $table WHERE backup_id = %s", $restore_id));
        if ($verify_job) {
            error_log('[WP Vault] Verified status in database: ' . $verify_job->status . ' (progress: ' . $verify_job->progress_percent . '%)');
        } else {
            error_log('[WP Vault] WARNING: Could not verify restore job status after update (restore_id: ' . $restore_id . ')');
        }

        if ($result['success']) {
            error_log('[WP Vault] Restore completed: ' . $restore_id);
        } else {
            error_log('[WP Vault] Restore failed: ' . $result['error']);
        }
    }

    /**
     * AJAX: Save storage preference (called after successful test connection)
     */
    public function ajax_save_storage_preference()
    {
        check_ajax_referer('wp-vault', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }

        $storage_type = isset($_POST['storage_type']) ? sanitize_text_field($_POST['storage_type']) : '';

        if (empty($storage_type)) {
            wp_send_json_error(array('message' => 'Storage type is required'));
        }

        // Save storage type preference
        update_option('wpv_storage_type', $storage_type);

        // If no primary storage is set, set this as primary
        $primary_storage = get_option('wpv_primary_storage_type', '');
        if (empty($primary_storage)) {
            update_option('wpv_primary_storage_type', $storage_type);
        }

        wp_send_json_success(array(
            'message' => 'Storage preference saved',
            'storage_type' => $storage_type,
            'is_primary' => get_option('wpv_primary_storage_type', '') === $storage_type,
        ));
    }

    /**
     * AJAX: Make storage primary
     */
    public function ajax_make_primary_storage()
    {
        check_ajax_referer('wp-vault', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }

        $storage_type = isset($_POST['storage_type']) ? sanitize_text_field($_POST['storage_type']) : '';

        if (empty($storage_type)) {
            wp_send_json_error(array('message' => 'Storage type is required'));
        }

        // Set as primary storage
        update_option('wpv_primary_storage_type', $storage_type);
        update_option('wpv_storage_type', $storage_type); // Also update current selection

        wp_send_json_success(array(
            'message' => 'Storage set as primary',
            'storage_type' => $storage_type,
        ));
    }

    /**
     * AJAX: Cleanup temp files
     */
    public function ajax_cleanup_temp_files()
    {
        check_ajax_referer('wp-vault', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }

        require_once WP_VAULT_PLUGIN_DIR . 'includes/class-wp-vault-temp-manager.php';
        $temp_manager = new \WP_Vault\WP_Vault_Temp_Manager();

        // Clean up files older than 1 hour
        $cleaned = $temp_manager->cleanup_old_files(3600);

        wp_send_json_success(array(
            'message' => sprintf(__('Cleaned up %d temporary files', 'wp-vault'), $cleaned),
            'cleaned' => $cleaned,
        ));
    }

    /**
     * AJAX: Read log file
     */
    public function ajax_read_log()
    {
        check_ajax_referer('wp-vault', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
            return;
        }

        $log_file_path = isset($_POST['log_file']) ? sanitize_text_field($_POST['log_file']) : '';
        $lines = isset($_POST['lines']) ? intval($_POST['lines']) : 100;
        $offset = isset($_POST['offset']) ? intval($_POST['offset']) : 0;

        if (empty($log_file_path)) {
            wp_send_json_error(array('message' => 'Log file path required'));
            return;
        }

        require_once WP_VAULT_PLUGIN_DIR . 'includes/class-wp-vault-log.php';
        $result = WP_Vault_Log::read_log($log_file_path, $lines, $offset);

        if (isset($result['error'])) {
            wp_send_json_error(array('message' => $result['error']));
            return;
        }

        wp_send_json_success(array(
            'content' => $result['content'],
            'total_lines' => $result['total_lines'],
            'offset' => $result['offset'],
        ));
    }

    /**
     * AJAX: Download log file
     */
    public function ajax_download_log()
    {
        check_ajax_referer('wp-vault', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
            return;
        }

        $log_file_path = isset($_GET['log_file']) ? sanitize_text_field($_GET['log_file']) : '';

        if (empty($log_file_path)) {
            wp_die('Log file path required');
            return;
        }

        // Validate path is in wp-vault-logs directory
        $log_dir = WP_CONTENT_DIR . DIRECTORY_SEPARATOR . 'wp-vault-logs' . DIRECTORY_SEPARATOR;
        $real_log_path = realpath($log_file_path);
        $real_log_dir = realpath($log_dir);

        if (!$real_log_path || !$real_log_dir || strpos($real_log_path, $real_log_dir) !== 0) {
            wp_die('Invalid log file path');
            return;
        }

        if (!file_exists($log_file_path)) {
            wp_die('Log file not found');
            return;
        }

        // Force download
        header('Content-Type: text/plain');
        header('Content-Disposition: attachment; filename="' . basename($log_file_path) . '"');
        header('Content-Length: ' . filesize($log_file_path));
        readfile($log_file_path);
        exit;
    }
}
