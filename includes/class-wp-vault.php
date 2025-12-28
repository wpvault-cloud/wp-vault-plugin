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

        // Detect and store host capabilities
        WP_Vault_Host_Detector::get_host_class();

        // Load text domain
        add_action('init', array($this, 'load_textdomain'));

        // Admin menu and pages
        if (is_admin()) {
            add_action('admin_menu', array($this, 'add_admin_menu'));
            add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
            // Redirect old pages early, before any output
            add_action('admin_init', array($this, 'redirect_old_pages'));
        }

        // Admin bar menu (works on both frontend and backend)
        add_action('admin_bar_menu', array($this, 'add_admin_bar_menu'), 100);

        // Register AJAX handlers
        add_action('wp_ajax_wpv_test_connection', array($this, 'ajax_test_connection'));
        add_action('wp_ajax_wpv_trigger_backup', array($this, 'ajax_trigger_backup'));
        add_action('wp_ajax_wpv_get_backup_status', array($this, 'ajax_get_backup_status')); // Listener for progress polling
        add_action('wp_ajax_wpv_get_backups', array($this, 'ajax_get_backups'));
        add_action('wp_ajax_wpv_restore_backup', array($this, 'ajax_restore_backup'));
        add_action('wp_ajax_wpv_get_restore_status', array($this, 'ajax_get_restore_status'));
        add_action('wp_ajax_wpv_delete_backup', array($this, 'ajax_delete_backup'));
        add_action('wp_ajax_wpv_download_backup_from_remote', array($this, 'ajax_download_backup_from_remote'));
        add_action('wp_ajax_wpv_get_download_status', array($this, 'ajax_get_download_status'));
        add_action('wp_ajax_wpv_remove_backup_from_db', array($this, 'ajax_remove_backup_from_db'));
        add_action('wp_ajax_wpv_save_storage_preference', array($this, 'ajax_save_storage_preference'));
        add_action('wp_ajax_wpv_make_primary_storage', array($this, 'ajax_make_primary_storage'));
        add_action('wp_ajax_wpv_cleanup_temp_files', array($this, 'ajax_cleanup_temp_files'));
        add_action('wp_ajax_wpv_download_backup', array($this, 'ajax_download_backup'));
        add_action('wp_ajax_wpv_download_backup_file', array($this, 'ajax_download_backup_file'));
        add_action('wp_ajax_wpv_read_log', array($this, 'ajax_read_log'));
        add_action('wp_ajax_wpv_download_log', array($this, 'ajax_download_log'));
        add_action('wp_ajax_wpv_get_saas_storages', array($this, 'ajax_get_saas_storages'));
        add_action('wp_ajax_wpv_set_primary_storage', array($this, 'ajax_set_primary_storage'));
        add_action('wp_ajax_wpv_get_job_logs', array($this, 'ajax_get_job_logs'));
        add_action('wp_ajax_wpv_cancel_restore', array($this, 'ajax_cancel_restore'));
        add_action('wp_ajax_wpv_check_connection', array($this, 'ajax_check_connection'));

        // Backup execution (async)
        add_action('wpvault_execute_backup', array($this, 'execute_backup'), 10, 2);

        // Heartbeat (runs every 6 hours)
        if (!wp_next_scheduled('wpv_heartbeat')) {
            wp_schedule_event(time(), 'twicedaily', 'wpv_heartbeat');
        }
        add_action('wpv_heartbeat', array($this, 'send_heartbeat'));

        // Poll for pending jobs (runs every 10 minutes)
        // This implements the "pull" part of the hybrid model
        if (!wp_next_scheduled('wpv_poll_pending_jobs')) {
            wp_schedule_event(time(), 'wpv_10min', 'wpv_poll_pending_jobs');
        }
        add_action('wpv_poll_pending_jobs', array($this, 'poll_pending_jobs'));

        // Register custom cron interval (10 minutes)
        add_filter('cron_schedules', array($this, 'add_cron_intervals'));

        // Register REST API routes for SaaS to trigger backups
        // Use priority 20 to ensure it runs after other plugins
        add_action('rest_api_init', array($this, 'register_rest_routes'), 20);

        // Also register on init as a fallback (some hosts have issues with rest_api_init)
        add_action('init', array($this, 'maybe_register_rest_routes'), 20);

        // Check for pending jobs on admin page loads (immediate polling)
        if (is_admin()) {
            add_action('admin_init', array($this, 'check_pending_jobs_on_load'));
            // Also check for local pending jobs that might be stuck
            add_action('admin_init', array($this, 'execute_pending_local_jobs'));
        }
    }

    /**
     * Register REST API routes
     */
    public function register_rest_routes()
    {
        // Ensure the REST API class is loaded
        if (!class_exists('WP_Vault\\WP_Vault_REST_API')) {
            require_once WP_VAULT_PLUGIN_DIR . 'includes/class-wp-vault-rest-api.php';
        }

        // Register the routes
        WP_Vault_REST_API::register_routes();

        // Debug: Log route registration (only in debug mode)
        if (defined('WP_DEBUG') && WP_DEBUG) {
            $hook = current_filter();
            error_log("WP Vault: REST API routes registered via hook: {$hook}");
            error_log("WP Vault: REST API base URL: " . rest_url('wp-vault/v1/'));
        }
    }

    /**
     * Fallback REST API route registration on init
     * Some hosting environments have issues with rest_api_init hook
     */
    public function maybe_register_rest_routes()
    {
        // Always register routes on init as fallback
        // This ensures routes are available even if rest_api_init doesn't fire
        $this->register_rest_routes();
    }

    /**
     * Check for pending jobs when admin pages load (immediate polling)
     * This ensures pending jobs are picked up quickly without waiting for cron
     */
    public function check_pending_jobs_on_load()
    {
        // Only check on WP Vault admin pages to avoid overhead
        $screen = get_current_screen();
        if (!$screen || strpos($screen->id, 'wp-vault') === false) {
            return;
        }

        // Check if we've polled recently (avoid too frequent checks)
        $last_poll = get_transient('wpv_last_pending_job_poll');
        if ($last_poll && (time() - $last_poll) < 60) {
            // Polled within last minute, skip
            return;
        }

        // Set transient to prevent multiple simultaneous polls
        set_transient('wpv_last_pending_job_poll', time(), 60);

        // Trigger polling in background (non-blocking)
        wp_schedule_single_event(time(), 'wpv_poll_pending_jobs');
        spawn_cron();
    }

    /**
     * Ensure database tables exist (runs on every page load)
     */
    private function ensure_tables_exist()
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset_collate = $wpdb->get_charset_collate();

        // Only create wp_wp_vault_jobs table (the only table actually used)
        // Note: wp_vault_settings and wp_vault_file_index are created by activator for backward compatibility
        // but are not actively used (settings use WordPress options, file_index not implemented)
        // Create wp_wp_vault_jobs table if it doesn't exist
        $table_jobs = $wpdb->prefix . 'wp_vault_jobs';
        $table_jobs_escaped = esc_sql($table_jobs);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema check, table name is escaped
        $jobs_table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_jobs));
        if (!$jobs_table_exists) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- dbDelta requires table name in CREATE TABLE, table name is escaped
            $sql_jobs = "CREATE TABLE IF NOT EXISTS {$table_jobs_escaped} (
                id bigint(20) NOT NULL AUTO_INCREMENT,
                job_type varchar(50) NOT NULL,
                status varchar(50) DEFAULT 'pending',
                backup_id varchar(255),
                progress_percent int(3) DEFAULT 0,
                total_size_bytes bigint(20) DEFAULT 0,
                started_at datetime,
                finished_at datetime,
                error_message text,
                created_at datetime DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY status (status),
                KEY backup_id (backup_id)
            ) $charset_collate;";
            dbDelta($sql_jobs);
        }

        // Now check and add updated_at column to wp_wp_vault_jobs if missing
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema check for migration, table name is escaped
        $updated_at_exists = $wpdb->get_results($wpdb->prepare("SHOW COLUMNS FROM {$table_jobs_escaped} LIKE %s", 'updated_at'));
        if (empty($updated_at_exists)) {
            // Table name is safe (from prefix), but we still use esc_sql for safety
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Schema migration, table name is escaped
            $wpdb->query("ALTER TABLE {$table_jobs_escaped} ADD COLUMN updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at");
        }

        // Check and add log_file_path column to wp_wp_vault_jobs if missing
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema check for migration, table name is escaped
        $log_file_path_exists = $wpdb->get_results($wpdb->prepare("SHOW COLUMNS FROM {$table_jobs_escaped} LIKE %s", 'log_file_path'));
        if (empty($log_file_path_exists)) {
            // Check if error_message exists before using AFTER
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema check for migration, table name is escaped
            $error_message_exists = $wpdb->get_results($wpdb->prepare("SHOW COLUMNS FROM {$table_jobs_escaped} LIKE %s", 'error_message'));
            if (!empty($error_message_exists)) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Schema migration, table name is escaped
                $wpdb->query("ALTER TABLE {$table_jobs_escaped} ADD COLUMN log_file_path varchar(255) DEFAULT NULL AFTER error_message");
            } else {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Schema migration, table name is escaped
                $wpdb->query("ALTER TABLE {$table_jobs_escaped} ADD COLUMN log_file_path varchar(255) DEFAULT NULL");
            }
        }

        // Check and add cursor column for resumable jobs (cursor is a reserved keyword, must escape)
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema check for migration, table name is escaped
        $cursor_exists = $wpdb->get_results($wpdb->prepare("SHOW COLUMNS FROM {$table_jobs_escaped} LIKE %s", 'cursor'));
        if (empty($cursor_exists)) {
            // Check if log_file_path exists before using AFTER
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema check for migration, table name is escaped
            $log_file_path_check = $wpdb->get_results($wpdb->prepare("SHOW COLUMNS FROM {$table_jobs_escaped} LIKE %s", 'log_file_path'));
            if (!empty($log_file_path_check)) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Schema migration, table name is escaped
                $wpdb->query("ALTER TABLE {$table_jobs_escaped} ADD COLUMN `cursor` TEXT DEFAULT NULL AFTER log_file_path");
            } else {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Schema migration, table name is escaped
                $wpdb->query("ALTER TABLE {$table_jobs_escaped} ADD COLUMN `cursor` TEXT DEFAULT NULL");
            }
        }

        // Check and add phase column for job phase tracking
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema check for migration, table name is escaped
        $phase_exists = $wpdb->get_results($wpdb->prepare("SHOW COLUMNS FROM {$table_jobs_escaped} LIKE %s", 'phase'));
        if (empty($phase_exists)) {
            // Check if cursor exists before using AFTER
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema check for migration, table name is escaped
            $cursor_check = $wpdb->get_results($wpdb->prepare("SHOW COLUMNS FROM {$table_jobs_escaped} LIKE %s", 'cursor'));
            if (!empty($cursor_check)) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Schema migration, table name is escaped
                $wpdb->query("ALTER TABLE {$table_jobs_escaped} ADD COLUMN phase varchar(20) DEFAULT NULL AFTER cursor");
            } else {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Schema migration, table name is escaped
                $wpdb->query("ALTER TABLE {$table_jobs_escaped} ADD COLUMN phase varchar(20) DEFAULT NULL");
            }
        }

        // Drop wp_vault_job_logs table (replaced by file-based logging)
        // This is a migration - table is no longer needed
        $table_job_logs = $wpdb->prefix . 'wp_vault_job_logs';
        $table_job_logs_escaped = esc_sql($table_job_logs);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema check for migration, table name is escaped
        $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_job_logs));
        if ($table_exists) {
            // Table name is safe (from prefix), but we use esc_sql for safety
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Schema migration, table name is escaped
            $wpdb->query("DROP TABLE IF EXISTS {$table_job_logs_escaped}");
        }

        // Add metadata columns for tracking backup source
        // Add schedule_id column if missing
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema check for migration, table name is escaped
        $schedule_id_exists = $wpdb->get_results($wpdb->prepare("SHOW COLUMNS FROM {$table_jobs_escaped} LIKE %s", 'schedule_id'));
        if (empty($schedule_id_exists)) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Schema migration, table name is escaped
            $wpdb->query("ALTER TABLE {$table_jobs_escaped} ADD COLUMN schedule_id varchar(255) DEFAULT NULL AFTER backup_id");
        }

        // Add trigger_source column if missing (manual, schedule, api)
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema check for migration, table name is escaped
        $trigger_source_exists = $wpdb->get_results($wpdb->prepare("SHOW COLUMNS FROM {$table_jobs_escaped} LIKE %s", 'trigger_source'));
        if (empty($trigger_source_exists)) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Schema migration, table name is escaped
            $wpdb->query("ALTER TABLE {$table_jobs_escaped} ADD COLUMN trigger_source varchar(50) DEFAULT 'manual' AFTER schedule_id");
        }

        // Now that tables exist, add missing columns (migrations)
        // Add total_size_bytes column if missing (already checked above, but check again to be safe)
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema check for migration, table name is escaped
        $total_size_exists = $wpdb->get_results($wpdb->prepare("SHOW COLUMNS FROM {$table_jobs_escaped} LIKE %s", 'total_size_bytes'));
        if (empty($total_size_exists)) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Schema migration, table name is escaped
            $wpdb->query("ALTER TABLE {$table_jobs_escaped} ADD COLUMN total_size_bytes bigint(20) DEFAULT 0 AFTER progress_percent");
        }

        // Add new columns for chunked processing and resume functionality
        $columns_to_add = array(
            'compression_mode' => "ALTER TABLE {$table_jobs_escaped} ADD COLUMN compression_mode VARCHAR(20) DEFAULT 'fast' AFTER job_type",
            'current_step' => "ALTER TABLE {$table_jobs_escaped} ADD COLUMN current_step VARCHAR(50) AFTER status",
            'current_component' => "ALTER TABLE {$table_jobs_escaped} ADD COLUMN current_component VARCHAR(50) AFTER current_step",
            'current_offset' => "ALTER TABLE {$table_jobs_escaped} ADD COLUMN current_offset BIGINT DEFAULT 0 AFTER current_component",
            'resume_data' => "ALTER TABLE {$table_jobs_escaped} ADD COLUMN resume_data TEXT AFTER current_offset",
            'components' => "ALTER TABLE {$table_jobs_escaped} ADD COLUMN components TEXT AFTER resume_data",
        );

        foreach ($columns_to_add as $column_name => $sql) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema check for migration, table name is escaped
            $column_exists = $wpdb->get_results($wpdb->prepare("SHOW COLUMNS FROM {$table_jobs_escaped} LIKE %s", $column_name));
            if (empty($column_exists)) {
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- ALTER TABLE statements cannot use placeholders, schema migration
                $wpdb->query($sql);
            }
        }

        // Create wp_vault_backup_history table for tracking all backups
        $table_backup_history = $wpdb->prefix . 'wp_vault_backup_history';
        $table_backup_history_escaped = esc_sql($table_backup_history);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema check, table name is escaped
        $backup_history_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_backup_history));
        if (!$backup_history_exists) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- dbDelta requires table name in CREATE TABLE, table name is escaped
            $sql_backup_history = "CREATE TABLE IF NOT EXISTS {$table_backup_history_escaped} (
                id bigint(20) NOT NULL AUTO_INCREMENT,
                backup_id varchar(255) NOT NULL,
                job_type varchar(50) DEFAULT 'backup',
                backup_type varchar(50) DEFAULT 'full',
                status varchar(50) DEFAULT 'pending',
                total_size_bytes bigint(20) DEFAULT 0,
                progress_percent int(3) DEFAULT 0,
                source varchar(50) DEFAULT 'local',
                has_local_files tinyint(1) DEFAULT 0,
                has_remote_files tinyint(1) DEFAULT 0,
                trigger_source varchar(50) DEFAULT 'manual',
                schedule_id varchar(255) DEFAULT NULL,
                started_at datetime DEFAULT NULL,
                finished_at datetime DEFAULT NULL,
                error_message text DEFAULT NULL,
                created_at datetime DEFAULT CURRENT_TIMESTAMP,
                updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY backup_id (backup_id),
                KEY status (status),
                KEY created_at (created_at)
            ) $charset_collate;";
            dbDelta($sql_backup_history);
        }

        // Create wp_vault_restore_history table for tracking all restores
        $table_restore_history = $wpdb->prefix . 'wp_vault_restore_history';
        $table_restore_history_escaped = esc_sql($table_restore_history);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema check, table name is escaped
        $restore_history_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_restore_history));
        if (!$restore_history_exists) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- dbDelta requires table name in CREATE TABLE, table name is escaped
            $sql_restore_history = "CREATE TABLE IF NOT EXISTS {$table_restore_history_escaped} (
                id bigint(20) NOT NULL AUTO_INCREMENT,
                restore_id varchar(255) NOT NULL,
                backup_id varchar(255) DEFAULT NULL,
                status varchar(50) DEFAULT 'pending',
                progress_percent int(3) DEFAULT 0,
                restore_mode varchar(50) DEFAULT 'full',
                components text DEFAULT NULL,
                started_at datetime DEFAULT NULL,
                finished_at datetime DEFAULT NULL,
                error_message text DEFAULT NULL,
                created_at datetime DEFAULT CURRENT_TIMESTAMP,
                updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY restore_id (restore_id),
                KEY backup_id (backup_id),
                KEY status (status),
                KEY created_at (created_at)
            ) $charset_collate;";
            dbDelta($sql_restore_history);
        }
    }

    /**
     * Load text domain
     * Note: WordPress.org automatically loads text domains, but we keep this for backward compatibility
     */
    public function load_textdomain()
    {
        // WordPress.org automatically loads text domains from /languages directory
        // This method is kept for backward compatibility with custom installations
        // phpcs:ignore PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound -- Kept for backward compatibility
        if (!function_exists('load_plugin_textdomain')) {
            return;
        }
        // phpcs:ignore PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound -- WordPress.org automatically loads text domains, but this ensures compatibility with non-WordPress.org installations
        load_plugin_textdomain('wp-vault', false, dirname(plugin_basename(WP_VAULT_PLUGIN_FILE)) . '/languages');
    }

    /**
     * Add admin menu
     */
    public function add_admin_menu()
    {
        add_menu_page(
            esc_html__('WP Vault', 'wp-vault'),
            esc_html__('WP Vault', 'wp-vault'),
            'manage_options',
            'wp-vault',
            array($this, 'render_dashboard_page'),
            'dashicons-cloud-upload',
            30
        );

        // Dashboard (main page - shows last 5 backups)
        add_submenu_page(
            'wp-vault',
            esc_html__('Dashboard', 'wp-vault'),
            esc_html__('Dashboard', 'wp-vault'),
            'manage_options',
            'wp-vault',
            array($this, 'render_dashboard_page')
        );

        // Backups (shows all backups)
        add_submenu_page(
            'wp-vault',
            esc_html__('Backups', 'wp-vault'),
            esc_html__('Backups', 'wp-vault'),
            'manage_options',
            'wp-vault-backups',
            array($this, 'render_dashboard_page')
        );

        // Restores (shows restore history)
        add_submenu_page(
            'wp-vault',
            esc_html__('Restores', 'wp-vault'),
            esc_html__('Restores', 'wp-vault'),
            'manage_options',
            'wp-vault-restores',
            array($this, 'render_dashboard_page')
        );

        // Storage (moved from Settings tab, menu item renamed from "Settings")
        add_submenu_page(
            'wp-vault',
            esc_html__('Storage', 'wp-vault'),
            esc_html__('Storage', 'wp-vault'),
            'manage_options',
            'wp-vault-settings', // Keep old slug for compatibility
            array($this, 'render_dashboard_page') // Render dashboard with storage tab
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
                'testing' => esc_html__('Testing connection...', 'wp-vault'),
                'success' => esc_html__('Connection successful!', 'wp-vault'),
                'failed' => esc_html__('Connection failed', 'wp-vault'),
            ),
        ));
    }

    /**
     * Render dashboard page (unified tabbed interface)
     */
    public function render_dashboard_page()
    {
        require_once WP_VAULT_PLUGIN_DIR . 'admin/dashboard.php';
    }

    /**
     * Redirect old pages early (before any output)
     */
    public function redirect_old_pages()
    {
        $page = isset($_GET['page']) ? sanitize_text_field($_GET['page']) : '';

        // Remove restores redirect since we removed that menu item
        // Backups page will render dashboard with backups tab automatically
    }

    /**
     * Redirect old submenu pages to dashboard with appropriate tab
     * (Fallback if admin_init redirect didn't work)
     */
    public function redirect_to_dashboard_tab()
    {
        $page = isset($_GET['page']) ? sanitize_text_field($_GET['page']) : '';
        $tab = 'backups';

        if ($page === 'wp-vault-backups') {
            $tab = 'backups';
        } elseif ($page === 'wp-vault-restores') {
            $tab = 'backups'; // Restores are handled in backups tab
        }

        // Use JavaScript redirect as fallback since headers may already be sent
        echo '<script>window.location.href = "' . esc_js(esc_url(admin_url('admin.php?page=wp-vault&tab=' . $tab))) . '";</script>';
        exit;
    }

    /**
     * Render backups page (legacy - redirects to dashboard)
     */
    public function render_backups_page()
    {
        // Use JavaScript redirect since headers may already be sent
        echo '<script>window.location.href = "' . esc_js(esc_url(admin_url('admin.php?page=wp-vault&tab=backups'))) . '";</script>';
        exit;
    }

    /**
     * Render restores page (legacy - redirects to dashboard)
     */
    public function render_restores_page()
    {
        // Use JavaScript redirect since headers may already be sent
        echo '<script>window.location.href = "' . esc_js(esc_url(admin_url('admin.php?page=wp-vault&tab=backups'))) . '";</script>';
        exit;
    }

    /**
     * Render settings page (can be accessed both as submenu and tab)
     */
    public function render_settings_page()
    {
        // If accessed from submenu, redirect to dashboard with settings tab
        // But also allow direct access for backward compatibility
        if (!isset($_GET['tab'])) {
            // Check if we should redirect or show standalone
            // For now, show standalone settings page
            require_once WP_VAULT_PLUGIN_DIR . 'admin/settings.php';
        } else {
            require_once WP_VAULT_PLUGIN_DIR . 'admin/dashboard.php';
        }
    }

    /**
     * Add admin bar menu
     */
    public function add_admin_bar_menu($wp_admin_bar)
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        // Main menu item
        $wp_admin_bar->add_node(array(
            'id' => 'wp-vault',
            'title' => '<span class="ab-icon dashicons-cloud-upload"></span> <span class="ab-label">WP Vault</span>',
            'href' => admin_url('admin.php?page=wp-vault'),
            'meta' => array(
                'title' => esc_html__('WP Vault', 'wp-vault'),
            ),
        ));

        // Dashboard
        $wp_admin_bar->add_node(array(
            'id' => 'wp-vault-dashboard',
            'parent' => 'wp-vault',
            'title' => esc_html__('Dashboard', 'wp-vault'),
            'href' => admin_url('admin.php?page=wp-vault'),
        ));

        // Backup Now
        $wp_admin_bar->add_node(array(
            'id' => 'wp-vault-backup-now',
            'parent' => 'wp-vault',
            'title' => esc_html__('Backup Now', 'wp-vault'),
            'href' => esc_url(admin_url('admin.php?page=wp-vault&tab=backups')),
            'meta' => array(
                'onclick' => 'jQuery("#wpv-backup-now").click(); return false;',
            ),
        ));

        // Dashboard
        $wp_admin_bar->add_node(array(
            'id' => 'wp-vault-dashboard-menu',
            'parent' => 'wp-vault',
            'title' => esc_html__('Dashboard', 'wp-vault'),
            'href' => esc_url(admin_url('admin.php?page=wp-vault&tab=dashboard')),
        ));

        // View Backups
        $wp_admin_bar->add_node(array(
            'id' => 'wp-vault-backups',
            'parent' => 'wp-vault',
            'title' => esc_html__('View All Backups', 'wp-vault'),
            'href' => esc_url(admin_url('admin.php?page=wp-vault&tab=backups')),
        ));

        // Restores
        $wp_admin_bar->add_node(array(
            'id' => 'wp-vault-restores',
            'parent' => 'wp-vault',
            'title' => esc_html__('Restores', 'wp-vault'),
            'href' => esc_url(admin_url('admin.php?page=wp-vault&tab=restores')),
        ));

        // Storage (renamed from Settings in menu)
        $wp_admin_bar->add_node(array(
            'id' => 'wp-vault-storage',
            'parent' => 'wp-vault',
            'title' => esc_html__('Storage', 'wp-vault'),
            'href' => esc_url(admin_url('admin.php?page=wp-vault-settings')), // Uses old slug but goes to storage tab
        ));

        // SaaS Dashboard (if registered)
        if (get_option('wpv_site_id')) {
            $api_endpoint = get_option('wpv_api_endpoint', 'http://host.docker.internal:3000');
            $wp_admin_bar->add_node(array(
                'id' => 'wp-vault-saas-dashboard',
                'parent' => 'wp-vault',
                'title' => esc_html__('SaaS Dashboard', 'wp-vault'),
                'href' => esc_url($api_endpoint . '/dashboard'),
                'meta' => array(
                    'target' => '_blank',
                ),
            ));
        }
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

        $storage_type = isset($_POST['storage_type']) ? sanitize_text_field(wp_unslash($_POST['storage_type'])) : '';
        $config = isset($_POST['config']) && is_array($_POST['config']) ? array_map('sanitize_text_field', wp_unslash($_POST['config'])) : array();

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

        $backup_type = isset($_POST['backup_type']) ? sanitize_text_field(wp_unslash($_POST['backup_type'])) : 'full';

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
                    'schedule_id' => null,
                    'trigger_source' => 'manual',
                ),
                array('%s', '%s', '%s', '%d', '%s', '%s', '%s')
            );

            // Insert into backup_history for permanent tracking
            $history_table = $wpdb->prefix . 'wp_vault_backup_history';
            $wpdb->replace(
                $history_table,
                array(
                    'backup_id' => $backup_id,
                    'job_type' => 'backup',
                    'backup_type' => $backup_type,
                    'status' => 'pending',
                    'progress_percent' => 0,
                    'source' => 'local',
                    'has_local_files' => 0,
                    'has_remote_files' => 1, // Will be uploaded to SaaS
                    'trigger_source' => 'manual',
                    'schedule_id' => null,
                    'started_at' => current_time('mysql'),
                ),
                array('%s', '%s', '%s', '%s', '%d', '%s', '%d', '%d', '%s', '%s', '%s')
            );

            // Execute backup immediately in background (spawn process)
            // This avoids waiting for WP-Cron which only runs on page load
            spawn_cron();

            // Also schedule for WP-Cron as fallback
            wp_schedule_single_event(time(), 'wpvault_execute_backup', array($backup_id, $backup_type));

            // Trigger immediately via action hook
            do_action('wpvault_execute_backup', $backup_id, $backup_type);

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
     * Add custom cron intervals
     */
    public function add_cron_intervals($schedules)
    {
        $schedules['wpv_10min'] = array(
            'interval' => 600, // 10 minutes
            'display' => esc_html__('Every 10 Minutes', 'wp-vault')
        );
        return $schedules;
    }

    /**
     * Poll SaaS for pending backup jobs (Hybrid Model - Pull)
     * 
     * This runs every 10 minutes via wp-cron to check for pending jobs
     * that couldn't be pushed directly (e.g., site behind firewall)
     */
    public function poll_pending_jobs()
    {
        require_once WP_VAULT_PLUGIN_DIR . 'includes/class-wp-vault-api.php';
        $api = new WP_Vault_API();

        // Get site_id and site_token
        $site_id = get_option('wpv_site_id');
        $site_token = get_option('wpv_site_token');

        if (!$site_id || !$site_token) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[WP Vault] Cannot poll pending jobs: site not registered');
            }
            return;
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[WP Vault] Polling for pending jobs...');
        }

        // Poll SaaS for pending jobs
        $pending_jobs = $api->get_pending_jobs($site_id, $site_token);

        if (!$pending_jobs || !isset($pending_jobs['success']) || !$pending_jobs['success']) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[WP Vault] No pending jobs or error fetching: ' . (isset($pending_jobs['error']) ? $pending_jobs['error'] : 'unknown'));
            }
            return;
        }

        $jobs = isset($pending_jobs['pending_jobs']) ? $pending_jobs['pending_jobs'] : array();

        if (empty($jobs)) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[WP Vault] No pending jobs found');
            }
            return;
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[WP Vault] Found ' . count($jobs) . ' pending job(s)');
        }

        // Process each pending job
        foreach ($jobs as $job) {
            $backup_id = isset($job['backup_id']) ? $job['backup_id'] : null;
            $backup_type = isset($job['backup_type']) ? $job['backup_type'] : 'full';

            if (!$backup_id) {
                continue;
            }

            // Try to claim the job (prevents multiple instances from picking it up)
            $claimed = $api->claim_pending_job($site_id, $site_token, $backup_id);

            if (!$claimed || !isset($claimed['success']) || !$claimed['success']) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('[WP Vault] Failed to claim job ' . $backup_id . ': ' . (isset($claimed['error']) ? $claimed['error'] : 'already claimed'));
                }
                continue;
            }

            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[WP Vault] Claimed pending job: ' . $backup_id . ' (type: ' . $backup_type . ')');
            }

            // Create local job record
            global $wpdb;
            $table = $wpdb->prefix . 'wp_vault_jobs';

            // Check if job already exists locally
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $table WHERE backup_id = %s",
                $backup_id
            ));

            // Get schedule_id and trigger_source from job data
            $schedule_id = isset($job['schedule_id']) ? sanitize_text_field($job['schedule_id']) : null;
            $trigger_source = $schedule_id ? 'schedule' : 'poll';

            // DUPLICATE PREVENTION: Check if backup already exists and is running/completed
            if ($existing) {
                $existing_job = $wpdb->get_row($wpdb->prepare(
                    "SELECT status, schedule_id, trigger_source FROM $table WHERE backup_id = %s",
                    $backup_id
                ));

                if ($existing_job && in_array($existing_job->status, array('running', 'completed'))) {
                    if (defined('WP_DEBUG') && WP_DEBUG) {
                        error_log('[WP Vault] Backup ' . $backup_id . ' already exists with status: ' . $existing_job->status . ', skipping duplicate');
                    }
                    continue; // Skip this job - it's already being processed or completed
                }
            }

            if (!$existing) {
                $wpdb->insert(
                    $table,
                    array(
                        'backup_id' => $backup_id,
                        'job_type' => $backup_type,
                        'status' => 'pending',
                        'progress_percent' => 0,
                        'started_at' => current_time('mysql'),
                        'schedule_id' => $schedule_id,
                        'trigger_source' => $trigger_source,
                    ),
                    array('%s', '%s', '%s', '%d', '%s', '%s', '%s')
                );
            } else {
                // Update local job status to 'running' and metadata
                $wpdb->update(
                    $table,
                    array(
                        'status' => 'running',
                        'schedule_id' => $schedule_id,
                        'trigger_source' => $trigger_source,
                    ),
                    array('backup_id' => $backup_id),
                    array('%s', '%s', '%s'),
                    array('%s')
                );
            }

            // Insert/update backup_history for permanent tracking
            $history_table = $wpdb->prefix . 'wp_vault_backup_history';
            $wpdb->replace(
                $history_table,
                array(
                    'backup_id' => $backup_id,
                    'job_type' => 'backup',
                    'backup_type' => $backup_type,
                    'status' => $existing ? 'running' : 'pending',
                    'progress_percent' => 0,
                    'source' => 'remote',
                    'has_local_files' => 0,
                    'has_remote_files' => 1, // Will be uploaded to SaaS
                    'trigger_source' => $trigger_source,
                    'schedule_id' => $schedule_id,
                    'started_at' => current_time('mysql'),
                ),
                array('%s', '%s', '%s', '%s', '%d', '%s', '%d', '%d', '%s', '%s', '%s')
            );

            // Execute backup immediately
            // This will be picked up by the existing execute_backup method
            wp_schedule_single_event(time(), 'wpvault_execute_backup', array($backup_id, $backup_type));

            // Also trigger immediately
            do_action('wpvault_execute_backup', $backup_id, $backup_type);
        }
    }

    /**
     * Check for local pending jobs and execute them
     * This helps recover jobs that were created but not executed
     */
    public function execute_pending_local_jobs()
    {
        global $wpdb;
        $table = $wpdb->prefix . 'wp_vault_jobs';

        // Find pending jobs that are older than 1 minute (give initial execution time)
        $one_minute_ago = gmdate('Y-m-d H:i:s', time() - 60);
        $pending_jobs = $wpdb->get_results($wpdb->prepare(
            "SELECT backup_id, job_type FROM $table 
             WHERE status = %s 
             AND (started_at IS NULL OR started_at < %s)
             ORDER BY started_at ASC
             LIMIT 5",
            'pending',
            $one_minute_ago
        ));

        if (empty($pending_jobs)) {
            return;
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[WP Vault] Found ' . count($pending_jobs) . ' local pending job(s) to execute');
        }

        foreach ($pending_jobs as $job) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[WP Vault] Executing pending local job: ' . $job->backup_id);
            }

            // Update status to running
            $wpdb->update(
                $table,
                array('status' => 'running'),
                array('backup_id' => $job->backup_id),
                array('%s'),
                array('%s')
            );

            // Execute backup
            wp_schedule_single_event(time(), 'wpvault_execute_backup', array($job->backup_id, $job->job_type));
            do_action('wpvault_execute_backup', $job->backup_id, $job->job_type);
        }
    }

    /**
     * Execute backup (async)
     */
    public function execute_backup($backup_id, $backup_type)
    {
        // Note: set_time_limit() and ini_set() are discouraged by WordPress.org
        // These are kept for backward compatibility but may not work on all hosts
        // Hosts with restrictions will enforce their own limits

        // Debug logging (only in debug mode)
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[WP Vault] execute_backup called - Backup ID: ' . $backup_id . ', Type: ' . $backup_type);
            error_log('[WP Vault] Backup type check: ' . ($backup_type === 'incremental' ? 'INCREMENTAL FLOW' : 'STANDARD FLOW'));
        }

        // Handle incremental backups differently
        if ($backup_type === 'incremental') {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[WP Vault] Using incremental backup flow');
            }
            require_once WP_VAULT_PLUGIN_DIR . 'includes/class-wp-vault-file-scanner.php';
            require_once WP_VAULT_PLUGIN_DIR . 'includes/class-wp-vault-fingerprinter.php';
            require_once WP_VAULT_PLUGIN_DIR . 'includes/class-wp-vault-hasher.php';
            require_once WP_VAULT_PLUGIN_DIR . 'includes/class-wp-vault-api.php';
            require_once WP_VAULT_PLUGIN_DIR . 'includes/class-wp-vault-incremental-uploader.php';
            require_once WP_VAULT_PLUGIN_DIR . 'includes/class-wp-vault-log.php';

            try {
                $log = new WP_Vault_Log();
                $log->create_log_file($backup_id, 'backup');
                $api = new WP_Vault_API();

                // Store log file path in database
                global $wpdb;
                $table = $wpdb->prefix . 'wp_vault_jobs';
                $log_file_path = $log->get_log_file();
                $wpdb->update(
                    $table,
                    ['log_file_path' => $log_file_path],
                    ['backup_id' => $backup_id],
                    ['%s'],
                    ['%s']
                );

                // Send initial log to SaaS
                $api->send_log($backup_id, [
                    'severity' => 'INFO',
                    'step' => 'init',
                    'message' => 'Starting incremental backup',
                    'percent' => 0
                ]);

                $log->write_log('Starting incremental backup', 'info');

                // Step 1: Scan files (resumable)
                $log->write_log('Step 1: Scanning files...', 'info');
                $api->send_log($backup_id, [
                    'severity' => 'INFO',
                    'step' => 'scan',
                    'message' => 'Step 1: Scanning files...',
                    'percent' => 5
                ]);
                $scanner = new WP_Vault_File_Scanner();
                $files = [];
                $cursor = null;
                $scan_complete = false;

                // Keep scanning until complete (within time budget)
                $max_iterations = 100; // Safety limit
                $iteration = 0;
                while (!$scan_complete && $iteration < $max_iterations) {
                    $scan_result = $scanner->scan($cursor);
                    $files = array_merge($files, $scan_result['files']);
                    $cursor = $scan_result['cursor'];
                    $scan_complete = $scan_result['completed'] ?? false;
                    $iteration++;

                    if ($scan_complete) {
                        break;
                    }

                    // If we've scanned enough files for this run, continue in next iteration
                    // For now, continue scanning until complete
                }

                $log->write_log('Scanned ' . count($files) . ' files (completed: ' . ($scan_complete ? 'yes' : 'no') . ')', 'info');
                $api->send_log($backup_id, [
                    'severity' => 'INFO',
                    'step' => 'scan',
                    'message' => 'Scanned ' . count($files) . ' files',
                    'percent' => 15
                ]);

                if (empty($files)) {
                    throw new \Exception(esc_html('No files found to backup. This may indicate a scanning issue.'));
                }

                // Step 2: Fingerprint files (add full_path for fingerprinting)
                $log->write_log('Step 2: Fingerprinting files...', 'info');
                $api->send_log($backup_id, [
                    'severity' => 'INFO',
                    'step' => 'fingerprint',
                    'message' => 'Step 2: Fingerprinting files...',
                    'percent' => 20
                ]);
                foreach ($files as &$file) {
                    // Add full_path for fingerprinting
                    $file['full_path'] = WP_CONTENT_DIR . '/' . ltrim($file['path'], '/');
                }
                unset($file); // Break reference

                $fingerprint_result = WP_Vault_Fingerprinter::batch_fingerprint($files);
                $files = $fingerprint_result['files'];
                $log->write_log('Fingerprinted ' . $fingerprint_result['processed'] . ' files', 'info');
                $api->send_log($backup_id, [
                    'severity' => 'INFO',
                    'step' => 'fingerprint',
                    'message' => 'Fingerprinted ' . $fingerprint_result['processed'] . ' files',
                    'percent' => 30
                ]);

                // Step 3: Submit inventory to cloud
                $log->write_log('Step 3: Submitting inventory to cloud...', 'info');
                $api->send_log($backup_id, [
                    'severity' => 'INFO',
                    'step' => 'inventory',
                    'message' => 'Step 3: Submitting inventory to cloud...',
                    'percent' => 35
                ]);

                // Prepare file list for inventory
                $inventory_files = [];
                foreach ($files as $file) {
                    $inventory_files[] = [
                        'path' => $file['path'],
                        'size' => $file['size'],
                        'mtime' => $file['mtime'],
                        'fingerprint' => $file['fingerprint'] ?? null
                    ];
                }

                $inventory_result = $api->submit_inventory($inventory_files);
                if (!$inventory_result['success']) {
                    throw new \Exception(esc_html('Failed to submit inventory: ' . ($inventory_result['error'] ?? 'Unknown error')));
                }
                $log->write_log('Inventory submitted successfully', 'info');
                $api->send_log($backup_id, [
                    'severity' => 'INFO',
                    'step' => 'inventory',
                    'message' => 'Inventory submitted successfully',
                    'percent' => 40
                ]);

                // Step 4: Get incremental plan
                $log->write_log('Step 4: Requesting incremental plan...', 'info');
                $api->send_log($backup_id, [
                    'severity' => 'INFO',
                    'step' => 'plan',
                    'message' => 'Step 4: Requesting incremental plan...',
                    'percent' => 45
                ]);
                $plan_response = wp_remote_post($api->get_api_endpoint() . '/api/v1/plan-incremental', [
                    'headers' => ['Content-Type' => 'application/json'],
                    'body' => json_encode([
                        'site_id' => get_option('wpv_site_id'),
                        'site_token' => get_option('wpv_site_token'),
                        'files' => $inventory_files
                    ]),
                    'timeout' => 60
                ]);

                if (is_wp_error($plan_response)) {
                    throw new \Exception(esc_html('Failed to get incremental plan: ' . $plan_response->get_error_message()));
                }

                $plan_status = wp_remote_retrieve_response_code($plan_response);
                $plan_body = json_decode(wp_remote_retrieve_body($plan_response), true);

                if ($plan_status !== 200) {
                    $error_msg = isset($plan_body['error']) ? $plan_body['error'] : 'Unknown error';
                    if (isset($plan_body['requires_upgrade']) && $plan_body['requires_upgrade']) {
                        throw new \Exception(esc_html('Incremental backups require a paid plan. ' . $error_msg));
                    }
                    throw new \Exception(esc_html('Failed to get incremental plan (HTTP ' . $plan_status . '): ' . $error_msg));
                }

                if (!isset($plan_body['snapshot_id'])) {
                    $log->write_log('Plan response: ' . wp_remote_retrieve_body($plan_response), 'error');
                    throw new \Exception(esc_html('Invalid plan response: ' . wp_remote_retrieve_body($plan_response)));
                }

                $log->write_log('Incremental plan received. Snapshot ID: ' . $plan_body['snapshot_id'], 'info');
                $log->write_log('Files to upload: ' . count($plan_body['upload_required']), 'info');
                $api->send_log($backup_id, [
                    'severity' => 'INFO',
                    'step' => 'plan',
                    'message' => 'Incremental plan received. Files to upload: ' . count($plan_body['upload_required']),
                    'percent' => 50
                ]);

                // Step 5: Execute incremental upload
                $log->write_log('Step 5: Executing incremental upload...', 'info');
                $api->send_log($backup_id, [
                    'severity' => 'INFO',
                    'step' => 'upload',
                    'message' => 'Step 5: Executing incremental upload...',
                    'percent' => 55
                ]);
                $uploader = new WP_Vault_Incremental_Uploader($backup_id, $log);
                $upload_result = $uploader->execute($plan_body);

                if ($upload_result['success']) {
                    $log->write_log('Incremental backup completed successfully', 'info');
                    $api->send_log($backup_id, [
                        'severity' => 'INFO',
                        'step' => 'complete',
                        'message' => 'Incremental backup completed successfully',
                        'percent' => 100
                    ]);

                    // Update local job status
                    global $wpdb;
                    $table = $wpdb->prefix . 'wp_vault_jobs';
                    $total_size = 0;
                    foreach ($upload_result['data']['objects'] ?? [] as $obj) {
                        $total_size += $obj['size'] ?? 0;
                    }

                    $wpdb->update(
                        $table,
                        [
                            'status' => 'completed',
                            'progress_percent' => 100,
                            'total_size_bytes' => $total_size,
                            'finished_at' => current_time('mysql')
                        ],
                        ['backup_id' => $backup_id]
                    );

                    // Update SaaS
                    $api->update_job_status($backup_id, 'completed', $total_size);

                    if (defined('WP_DEBUG') && WP_DEBUG) {
                        error_log('[WP Vault] Incremental backup completed: ' . $backup_id);
                    }
                } else {
                    throw new \Exception(esc_html('Upload failed: ' . ($upload_result['error'] ?? 'Unknown error')));
                }

                $log->close_file();
            } catch (\Exception $e) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('[WP Vault] Incremental backup exception: ' . $e->getMessage());
                    error_log('[WP Vault] Exception trace: ' . $e->getTraceAsString());
                }

                // Update job status to failed
                global $wpdb;
                $table = $wpdb->prefix . 'wp_vault_jobs';
                $wpdb->update(
                    $table,
                    [
                        'status' => 'failed',
                        'error_message' => $e->getMessage(),
                        'finished_at' => current_time('mysql')
                    ],
                    ['backup_id' => $backup_id]
                );

                // Update SaaS
                if (isset($api)) {
                    $api->send_log($backup_id, [
                        'severity' => 'ERROR',
                        'step' => 'error',
                        'message' => 'Backup failed: ' . $e->getMessage(),
                        'percent' => 0
                    ]);
                    $api->update_job_status($backup_id, 'failed');
                }

                if (isset($log)) {
                    $log->write_log('Backup failed: ' . $e->getMessage(), 'error');
                    $log->close_file();
                }
            }
        } else {
            // Full backup, files only, or database only
            require_once WP_VAULT_PLUGIN_DIR . 'includes/class-wp-vault-backup-engine.php';

            try {
                $engine = new WP_Vault_Backup_Engine($backup_id, $backup_type);
                $result = $engine->execute();

                // Update SaaS with result
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    if ($result['success']) {
                        error_log('[WP Vault] Backup completed: ' . $backup_id);
                    } else {
                        error_log('[WP Vault] Backup failed: ' . $result['error']);
                    }
                }
            } catch (\Exception $e) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('[WP Vault] Backup exception: ' . $e->getMessage());
                    error_log('[WP Vault] Exception trace: ' . $e->getTraceAsString());
                }
            }
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

        $backup_id = isset($_POST['backup_id']) ? sanitize_text_field(wp_unslash($_POST['backup_id'])) : '';

        global $wpdb;
        $table = $wpdb->prefix . 'wp_vault_jobs';

        // Check if log_file_path column exists before querying
        $table_escaped = esc_sql($table);
        $column_exists = $wpdb->get_results($wpdb->prepare("SHOW COLUMNS FROM {$table_escaped} LIKE %s", 'log_file_path'));
        $log_file_path_column = empty($column_exists) ? '' : esc_sql('log_file_path');
        $log_file_path_select = empty($column_exists) ? '' : ', ' . $log_file_path_column;

        $job = $wpdb->get_row($wpdb->prepare("SELECT status, progress_percent, error_message, total_size_bytes{$log_file_path_select} FROM {$table_escaped} WHERE backup_id = %s", $backup_id));

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
            $job = $wpdb->get_row($wpdb->prepare("SELECT status, progress_percent, error_message, total_size_bytes{$log_file_path_select} FROM {$table_escaped} WHERE backup_id = %s", $backup_id));
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

        $backup_id = isset($_POST['backup_id']) ? sanitize_text_field(wp_unslash($_POST['backup_id'])) : '';
        $backup_file = isset($_POST['backup_file']) ? sanitize_text_field(wp_unslash($_POST['backup_file'])) : '';
        $backup_path = isset($_POST['backup_path']) ? sanitize_text_field(wp_unslash($_POST['backup_path'])) : '';
        $restore_mode = isset($_POST['restore_mode']) ? sanitize_text_field(wp_unslash($_POST['restore_mode'])) : 'full';

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

        $insert_result = $wpdb->insert(
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

        // Verify the job was created
        if ($insert_result === false) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[WP Vault] ERROR: Failed to create restore job. Last error: ' . $wpdb->last_error);
            }
            wp_send_json_error(array('error' => 'Failed to create restore job: ' . $wpdb->last_error));
        }

        // Verify the job exists after insert
        $verify_job = $wpdb->get_row($wpdb->prepare("SELECT backup_id FROM {$table} WHERE backup_id = %s", $restore_id));
        if (!$verify_job) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[WP Vault] ERROR: Restore job was not created. Insert returned: ' . ($insert_result !== false ? $insert_result : 'false'));
            }
            wp_send_json_error(array('error' => 'Restore job was not created in database'));
        }

        // Insert into restore_history for permanent tracking
        $restore_history_table = $wpdb->prefix . 'wp_vault_restore_history';
        $wpdb->replace(
            $restore_history_table,
            array(
                'restore_id' => $restore_id,
                'backup_id' => $backup_id,
                'status' => 'running',
                'progress_percent' => 0,
                'restore_mode' => $restore_mode,
                'components' => json_encode($components),
                'started_at' => current_time('mysql'),
            ),
            array('%s', '%s', '%s', '%d', '%s', '%s', '%s')
        );

        // Trigger restore in background (using WP-Cron)
        add_action('wpvault_execute_restore', array($this, 'execute_restore'), 10, 3);
        wp_schedule_single_event(time(), 'wpvault_execute_restore', array($restore_id, $backup_path, $restore_options));

        // Spawn cron immediately
        spawn_cron();
        do_action('wpvault_execute_restore', $restore_id, $backup_path, $restore_options);

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
        // Close session immediately to allow parallel requests
        if (session_id()) {
            session_write_close();
        }

        check_ajax_referer('wp-vault', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }

        $restore_id = isset($_POST['restore_id']) ? sanitize_text_field(wp_unslash($_POST['restore_id'])) : '';

        global $wpdb;
        $table = $wpdb->prefix . 'wp_vault_jobs';

        // Debug: Log the query we're about to run
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[WP Vault] AJAX Status Check - Querying for restore_id: ' . $restore_id);
        }

        // Check if log_file_path column exists before querying
        $table_escaped = esc_sql($table);
        $column_exists = $wpdb->get_results($wpdb->prepare("SHOW COLUMNS FROM {$table_escaped} LIKE %s", 'log_file_path'));
        $log_file_path_column = empty($column_exists) ? '' : esc_sql('log_file_path');
        $log_file_path_select = empty($column_exists) ? '' : ', ' . $log_file_path_column;

        $job = $wpdb->get_row($wpdb->prepare("SELECT status, progress_percent, error_message{$log_file_path_select} FROM {$table_escaped} WHERE backup_id = %s", $restore_id));

        if (!$job) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[WP Vault] AJAX Status Check - Job not found! Searching for restore_id: ' . $restore_id);
                // Try to find any restore jobs
                $all_restores = $wpdb->get_results($wpdb->prepare("SELECT backup_id, status FROM $table WHERE job_type = %s ORDER BY started_at DESC LIMIT 5", 'restore'));
                error_log('[WP Vault] AJAX Status Check - Found ' . count($all_restores) . ' restore jobs in database');
                foreach ($all_restores as $restore_job) {
                    error_log('[WP Vault] AJAX Status Check -   - ' . $restore_job->backup_id . ' (status: ' . $restore_job->status . ')');
                }
            }
            wp_send_json_error(array('message' => 'Restore job not found'));
        }

        // Debug logging
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[WP Vault] AJAX Status Check - Found job! Restore ID: ' . $restore_id . ', Status: ' . $job->status . ', Progress: ' . $job->progress_percent);
        }

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

        $backup_id = isset($_POST['backup_id']) ? sanitize_text_field(wp_unslash($_POST['backup_id'])) : '';
        $backup_file = isset($_POST['backup_file']) ? sanitize_text_field($_POST['backup_file']) : '';

        $backup_dir = WP_CONTENT_DIR . '/wp-vault-backups/';
        $deleted_count = 0;

        if (!empty($backup_id)) {
            // Delete by backup_id (all components)
            $manifest_file = $backup_dir . 'backup-' . $backup_id . '-manifest.json';

            if (file_exists($manifest_file)) {
                $manifest_data = json_decode(file_get_contents($manifest_file), true);
                if ($manifest_data && isset($manifest_data['files'])) {
                    // Delete all component files listed in manifest
                    foreach ($manifest_data['files'] as $file) {
                        $file_path = $backup_dir . $file['filename'];
                        if (file_exists($file_path)) {
                            if (wp_delete_file($file_path)) {
                                $deleted_count++;
                            }
                        }
                    }
                }
                // Delete manifest
                if (wp_delete_file($manifest_file)) {
                    $deleted_count++;
                }
            }

            // Also try to find and delete component files by pattern (in case manifest is missing)
            $patterns = array(
                $backup_dir . '{database,themes,plugins,uploads,wp-content}-' . $backup_id . '-*.tar.gz',
                $backup_dir . '{database,themes,plugins,uploads,wp-content}-' . $backup_id . '-*.sql.gz',
                $backup_dir . 'backup-' . $backup_id . '-*.tar.gz',
                $backup_dir . '*-' . $backup_id . '-*.tar.gz',
                $backup_dir . '*-' . $backup_id . '-*.sql.gz',
            );

            foreach ($patterns as $pattern) {
                $files = glob($pattern, GLOB_BRACE);
                if ($files && is_array($files)) {
                    foreach ($files as $file) {
                        if (file_exists($file)) {
                            // Security: ensure file is in backup directory
                            if (strpos(realpath($file), realpath($backup_dir)) === 0) {
                                if (wp_delete_file($file)) {
                                    $deleted_count++;
                                }
                            }
                        }
                    }
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

            if (wp_delete_file($backup_path)) {
                $deleted_count = 1;
            }
        } else {
            wp_send_json_error(array('error' => 'Backup ID or file not specified'));
        }

        // Check if any local files still exist after deletion
        $has_local_files = false;
        if (!empty($backup_id)) {
            $manifest_file = $backup_dir . 'backup-' . $backup_id . '-manifest.json';
            if (file_exists($manifest_file)) {
                $has_local_files = true;
            } else {
                // Check if any component files exist
                $patterns = array(
                    $backup_dir . '{database,themes,plugins,uploads,wp-content}-' . $backup_id . '-*.tar.gz',
                    $backup_dir . '{database,themes,plugins,uploads,wp-content}-' . $backup_id . '-*.sql.gz',
                    $backup_dir . 'backup-' . $backup_id . '-*.tar.gz',
                );
                foreach ($patterns as $pattern) {
                    $files = glob($pattern, GLOB_BRACE);
                    if ($files && is_array($files) && count($files) > 0) {
                        $has_local_files = true;
                        break;
                    }
                }
            }

            // Update backup_history table to reflect current local file status
            global $wpdb;
            $history_table = $wpdb->prefix . 'wp_vault_backup_history';
            $wpdb->update(
                $history_table,
                array('has_local_files' => $has_local_files ? 1 : 0),
                array('backup_id' => $backup_id),
                array('%d'),
                array('%s')
            );
        }

        if ($deleted_count > 0) {
            /* translators: %d: number of files deleted */
            wp_send_json_success(array(
                'message' => sprintf(esc_html__('Deleted %d local file(s)', 'wp-vault'), $deleted_count),
                'has_local_files' => $has_local_files,
                'deleted_count' => $deleted_count
            ));
        } else {
            // No local files found - this might be a cloud-only backup
            // Still return success since we're only deleting local backups
            wp_send_json_success(array(
                'message' => esc_html__('No local backup files found. This backup may be stored in cloud storage only.', 'wp-vault'),
                'has_local_files' => false,
                'cloud_only' => true
            ));
        }
    }

    /**
     * Download backup files from remote storage
     */
    public function ajax_download_backup_from_remote()
    {
        check_ajax_referer('wp-vault', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }

        // Close session to allow concurrent polling requests
        if (session_id()) {
            session_write_close();
        }

        $backup_id = isset($_POST['backup_id']) ? sanitize_text_field(wp_unslash($_POST['backup_id'])) : '';

        if (empty($backup_id)) {
            wp_send_json_error(array('error' => 'Backup ID not specified'));
        }

        // Create log file for download progress
        require_once WP_VAULT_PLUGIN_DIR . 'includes/class-wp-vault-log.php';
        $log_dir = WP_CONTENT_DIR . '/wp-vault-logs/';
        if (!file_exists($log_dir)) {
            wp_mkdir_p($log_dir);
        }
        $log_file = $log_dir . $backup_id . '_download_log.txt';
        $log = new \WP_Vault\WP_Vault_Log();
        $log->create_log_file($backup_id, 'download');
        $log->write_log('===== DOWNLOAD FROM REMOTE STARTED =====', 'info');
        $log->write_log('Backup ID: ' . $backup_id, 'info');

        require_once WP_VAULT_PLUGIN_DIR . 'includes/class-wp-vault-api.php';
        $api = new \WP_Vault\WP_Vault_API();

        // Get download URLs from SaaS
        $log->write_log('Fetching download URLs from remote storage...', 'info');
        $urls_result = $api->get_backup_download_urls($backup_id);

        if (!$urls_result['success']) {
            $log->write_log('Failed to get download URLs: ' . ($urls_result['error'] || 'Unknown error'), 'error');
            wp_send_json_error(array('error' => $urls_result['error'] || 'Failed to get download URLs'));
        }

        $download_urls = $urls_result['data']['download_urls'];
        if (empty($download_urls)) {
            $log->write_log('No download URLs found', 'error');
            wp_send_json_error(array('error' => 'No download URLs found'));
        }

        $total_chunks = count($download_urls);
        $log->write_log("Found {$total_chunks} chunk(s) to download", 'info');

        $backup_dir = WP_CONTENT_DIR . '/wp-vault-backups/';
        if (!file_exists($backup_dir)) {
            wp_mkdir_p($backup_dir);
        }

        // Create temp directory for extracting chunks
        $temp_dir = $backup_dir . 'temp-download-' . $backup_id . '/';
        if (!file_exists($temp_dir)) {
            wp_mkdir_p($temp_dir);
        }

        $downloaded_count = 0;
        $chunk_files = array();
        $errors = array();

        // Download each chunk
        $chunk_index = 0;
        foreach ($download_urls as $file_info) {
            $chunk_index++;
            $url = $file_info['url'];
            $filename = $file_info['filename'];
            $sequence = $file_info['sequence'];
            $chunk_path = $temp_dir . $filename;

            $progress = (int) (($chunk_index / $total_chunks) * 30); // 0-30% for downloading
            $log->write_log("Downloading chunk {$chunk_index}/{$total_chunks}: {$filename}", 'info');

            // Download chunk
            $response = wp_remote_get($url, array(
                'timeout' => 300,
                'stream' => true,
                'filename' => $chunk_path,
            ));

            if (is_wp_error($response)) {
                $error_msg = sprintf('Failed to download %s: %s', $filename, $response->get_error_message());
                $log->write_log($error_msg, 'error');
                $errors[] = sprintf(esc_html__('Failed to download %s: %s', 'wp-vault'), $filename, $response->get_error_message());
                continue;
            }

            $status_code = wp_remote_retrieve_response_code($response);
            if ($status_code !== 200) {
                $error_msg = sprintf('Failed to download %s: HTTP %d', $filename, $status_code);
                $log->write_log($error_msg, 'error');
                $errors[] = sprintf(esc_html__('Failed to download %s: HTTP %d', 'wp-vault'), $filename, $status_code);
                continue;
            }

            if (file_exists($chunk_path)) {
                $file_size = filesize($chunk_path);
                $log->write_log("Downloaded chunk {$chunk_index}/{$total_chunks}: {$filename} (" . size_format($file_size) . ")", 'info');
                $chunk_files[] = array(
                    'path' => $chunk_path,
                    'sequence' => $sequence,
                    'filename' => $filename,
                );
                $downloaded_count++;
            }
        }

        if ($downloaded_count === 0) {
            // Cleanup temp directory
            if (file_exists($temp_dir)) {
                $this->delete_directory($temp_dir);
            }
            wp_send_json_error(array(
                'error' => esc_html__('Failed to download any chunks', 'wp-vault'),
                'errors' => $errors,
            ));
        }

        // Sort chunks by sequence
        usort($chunk_files, function ($a, $b) {
            return $a['sequence'] - $b['sequence'];
        });

        $log->write_log("Downloaded {$downloaded_count}/{$total_chunks} chunk(s) successfully", 'info');
        $log->write_log('Extracting chunks...', 'info');

        // Extract chunks and combine into component files
        $component_files = array();
        $extract_dir = $temp_dir . 'extracted/';
        wp_mkdir_p($extract_dir);

        $extract_index = 0;
        foreach ($chunk_files as $chunk) {
            $extract_index++;
            $chunk_path = $chunk['path'];
            $progress = 30 + (int) (($extract_index / count($chunk_files)) * 20); // 30-50% for extraction

            $log->write_log("Extracting chunk {$extract_index}/" . count($chunk_files) . ": {$chunk['filename']}", 'info');

            // Check if this is the first chunk and might be a database file (.sql.gz)
            // Database files are uploaded as chunk-0000.tar.gz but are actually .sql.gz files
            $is_first_chunk = ($chunk['sequence'] == 0 || $extract_index == 1);
            $file_size = file_exists($chunk_path) ? filesize($chunk_path) : 0;

            // Try to detect if it's a gzip file (database) by checking file signature
            $is_gzip_file = false;
            if ($is_first_chunk && $file_size > 0) {
                // Check first 2 bytes for gzip magic number (0x1f 0x8b)
                $handle = fopen($chunk_path, 'rb');
                if ($handle) {
                    $header = fread($handle, 2);
                    fclose($handle);
                    if (strlen($header) === 2 && ord($header[0]) === 0x1f && ord($header[1]) === 0x8b) {
                        $is_gzip_file = true;
                        $log->write_log("Detected database file (gzip) in first chunk", 'info');
                    }
                }
            }

            // Extract tar.gz chunk or handle database file
            try {
                $chunk_extract_dir = $extract_dir . 'chunk-' . $chunk['sequence'] . '/';
                wp_mkdir_p($chunk_extract_dir);

                if ($is_gzip_file && $is_first_chunk) {
                    // This is a database file (.sql.gz) - copy it directly
                    $db_filename = 'database-' . $backup_id . '.sql.gz';
                    $db_path = $chunk_extract_dir . $db_filename;
                    if (copy($chunk_path, $db_path)) {
                        $log->write_log("Copied database file from chunk {$extract_index}/" . count($chunk_files), 'info');
                    } else {
                        throw new \Exception('Failed to copy database file');
                    }
                } else {
                    // Regular tar.gz chunk - extract normally
                    if (class_exists('PharData')) {
                        try {
                            $phar = new \PharData($chunk_path);
                            $phar->extractTo($chunk_extract_dir, null, true);
                        } catch (\Exception $phar_error) {
                            // If PharData fails, it might be a gzip file - try copying it
                            if ($is_first_chunk && strpos($phar_error->getMessage(), 'corrupted') !== false) {
                                $log->write_log("PharData extraction failed, trying as database file...", 'info');
                                $db_filename = 'database-' . $backup_id . '.sql.gz';
                                $db_path = $chunk_extract_dir . $db_filename;
                                if (copy($chunk_path, $db_path)) {
                                    $log->write_log("Copied database file from chunk (fallback)", 'info');
                                } else {
                                    throw $phar_error; // Re-throw original error
                                }
                            } else {
                                throw $phar_error;
                            }
                        }
                    } else {
                        // Fallback: use tar command if available
                        $tar_cmd = sprintf('tar -xzf %s -C %s', escapeshellarg($chunk_path), escapeshellarg($chunk_extract_dir));
                        exec($tar_cmd, $output, $return_code);
                        if ($return_code !== 0) {
                            // If tar fails and it's the first chunk, try as database file
                            if ($is_first_chunk) {
                                $log->write_log("Tar extraction failed, trying as database file...", 'info');
                                $db_filename = 'database-' . $backup_id . '.sql.gz';
                                $db_path = $chunk_extract_dir . $db_filename;
                                if (copy($chunk_path, $db_path)) {
                                    $log->write_log("Copied database file from chunk (tar fallback)", 'info');
                                } else {
                                    throw new \Exception('Failed to extract chunk: ' . implode(' ', $output));
                                }
                            } else {
                                throw new \Exception('Failed to extract chunk: ' . implode(' ', $output));
                            }
                        }
                    }
                }
                $log->write_log("Extracted chunk {$extract_index}/" . count($chunk_files) . " successfully", 'info');
            } catch (\Exception $e) {
                $error_msg = sprintf('Failed to extract chunk %s: %s', $chunk['filename'], $e->getMessage());
                $log->write_log($error_msg, 'error');
                $errors[] = sprintf(esc_html__('Failed to extract chunk %s: %s', 'wp-vault'), $chunk['filename'], $e->getMessage());
                continue;
            }
        }

        $log->write_log('Organizing files by component...', 'info');

        // Organize extracted files by component
        $components = array(
            'themes' => array(),
            'plugins' => array(),
            'uploads' => array(),
            'wp-content' => array(),
        );

        // Scan extracted directories and organize by component
        if (file_exists($extract_dir)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($extract_dir, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST
            );

            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $file_path = $file->getPathname();
                    $relative_path = str_replace($extract_dir, '', $file_path);

                    // Check if it's a database file (database.sql.gz or database-*.sql.gz)
                    if (preg_match('/database.*\.sql\.gz$/i', basename($file_path))) {
                        // Database file - copy directly
                        $db_filename = 'database-' . $backup_id . '.sql.gz';
                        $db_path = $backup_dir . $db_filename;
                        if (copy($file_path, $db_path)) {
                            $component_files[] = array(
                                'filename' => $db_filename,
                                'size' => filesize($db_path),
                                'component' => 'database',
                            );
                        }
                        continue;
                    }

                    // Determine component from path
                    if (strpos($relative_path, 'wp-content/themes/') !== false || strpos($relative_path, '/themes/') !== false) {
                        $components['themes'][] = $file_path;
                    } elseif (strpos($relative_path, 'wp-content/plugins/') !== false || strpos($relative_path, '/plugins/') !== false) {
                        $components['plugins'][] = $file_path;
                    } elseif (strpos($relative_path, 'wp-content/uploads/') !== false || strpos($relative_path, '/uploads/') !== false) {
                        $components['uploads'][] = $file_path;
                    } elseif (strpos($relative_path, 'wp-content/') !== false) {
                        $components['wp-content'][] = $file_path;
                    }
                }
            }
        }

        $log->write_log('Creating component archives...', 'info');

        // Create component archives
        $files = array();
        $component_index = 0;
        $total_components = count(array_filter($components, function ($list) {
            return !empty($list);
        }));

        foreach ($components as $component_name => $component_files_list) {
            if (empty($component_files_list)) {
                continue;
            }

            $component_index++;
            $progress = 50 + (int) (($component_index / max($total_components, 1)) * 40); // 50-90% for archiving
            $log->write_log("Creating archive for component: {$component_name} ({$component_index}/{$total_components})", 'info');

            $component_filename = $component_name . '-' . $backup_id . '.tar.gz';
            $component_path = $backup_dir . $component_filename;
            $component_tar_path = str_replace('.gz', '', $component_path);

            // Create tar.gz archive for component
            try {
                if (class_exists('PharData')) {
                    // Create tar file first
                    $phar = new \PharData($component_tar_path);
                    foreach ($component_files_list as $file_path) {
                        // Get relative path from extract_dir, preserving component structure
                        $relative_path = str_replace($extract_dir, '', $file_path);
                        // Normalize path separators
                        $relative_path = str_replace('\\', '/', $relative_path);
                        // Remove leading slashes
                        $relative_path = ltrim($relative_path, '/');
                        if (file_exists($file_path)) {
                            $phar->addFile($file_path, $relative_path);
                        }
                    }
                    // Compress to .tar.gz
                    $phar->compress(\Phar::GZ);
                    // Remove uncompressed tar if it exists
                    if (file_exists($component_tar_path)) {
                        wp_delete_file($component_tar_path);
                    }
                } else {
                    // Fallback: use tar command
                    $tar_cmd = sprintf(
                        'cd %s && tar -czf %s %s',
                        escapeshellarg($extract_dir),
                        escapeshellarg($component_path),
                        escapeshellarg(implode(' ', array_map(function ($f) use ($extract_dir) {
                            return str_replace($extract_dir, '', $f);
                        }, $component_files_list)))
                    );
                    exec($tar_cmd, $output, $return_code);
                    if ($return_code !== 0) {
                        throw new \Exception('Failed to create component archive: ' . implode(' ', $output));
                    }
                }

                if (file_exists($component_path)) {
                    $file_size = filesize($component_path);
                    $log->write_log("Created component archive: {$component_filename} (" . size_format($file_size) . ")", 'info');
                    $files[] = array(
                        'filename' => $component_filename,
                        'size' => $file_size,
                    );
                }
            } catch (\Exception $e) {
                $error_msg = sprintf('Failed to create component archive %s: %s', $component_name, $e->getMessage());
                $log->write_log($error_msg, 'error');
                $errors[] = sprintf(esc_html__('Failed to create component archive %s: %s', 'wp-vault'), $component_name, $e->getMessage());
            }
        }

        $log->write_log('Cleaning up temporary files...', 'info');

        // Cleanup temp directory
        if (file_exists($temp_dir)) {
            $this->delete_directory($temp_dir);
        }

        // Cleanup chunk files
        foreach ($chunk_files as $chunk) {
            if (file_exists($chunk['path'])) {
                wp_delete_file($chunk['path']);
            }
        }

        // Create or update manifest.json
        if (!empty($files) || !empty($component_files)) {
            $all_files = array_merge($files, $component_files);
            $manifest_file = $backup_dir . 'backup-' . $backup_id . '-manifest.json';
            $manifest_data = array(
                'backup_id' => $backup_id,
                'created_at' => gmdate('Y-m-d H:i:s'),
                'files' => $all_files,
            );
            file_put_contents($manifest_file, wp_json_encode($manifest_data, JSON_PRETTY_PRINT));
            $log->write_log('Created manifest.json', 'info');
        }

        $total_files = count($files) + count($component_files);
        $log->write_log("===== DOWNLOAD COMPLETE =====\nDownloaded {$downloaded_count} chunk(s) into {$total_files} component file(s)", 'info');

        if ($total_files > 0) {
            // Update backup_history to reflect that local files now exist
            global $wpdb;
            $history_table = $wpdb->prefix . 'wp_vault_backup_history';
            $wpdb->update(
                $history_table,
                array(
                    'has_local_files' => 1,
                    'source' => 'both', // Update source to 'both' if remote also exists
                ),
                array('backup_id' => $backup_id),
                array('%d', '%s'),
                array('%s')
            );

            wp_send_json_success(array(
                'message' => sprintf(esc_html__('Downloaded and combined %d chunk(s) into %d component file(s)', 'wp-vault'), $downloaded_count, $total_files),
                'downloaded_count' => $downloaded_count,
                'component_count' => $total_files,
                'has_local_files' => true,
                'errors' => $errors,
                'log_file' => $log_file,
            ));
        } else {
            $log->write_log('Download failed: No files created', 'error');
            wp_send_json_error(array(
                'error' => esc_html__('Failed to download or combine any files', 'wp-vault'),
                'errors' => $errors,
                'log_file' => $log_file,
            ));
        }
    }

    /**
     * Remove backup record from database
     */
    public function ajax_remove_backup_from_db()
    {
        check_ajax_referer('wp-vault', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }

        $backup_id = isset($_POST['backup_id']) ? sanitize_text_field(wp_unslash($_POST['backup_id'])) : '';

        if (empty($backup_id)) {
            wp_send_json_error(array('error' => 'Backup ID not specified'));
        }

        global $wpdb;
        $history_table = $wpdb->prefix . 'wp_vault_backup_history';

        // Check if record exists in backup_history
        $existing_record = $wpdb->get_row($wpdb->prepare(
            "SELECT backup_id, has_local_files FROM {$history_table} WHERE backup_id = %s LIMIT 1",
            $backup_id
        ));

        if (!$existing_record) {
            wp_send_json_success(array(
                'message' => esc_html__('Backup record not found in local database. It may only exist in cloud storage.', 'wp-vault'),
                'not_found' => true,
            ));
            return;
        }

        // Delete local files if they exist
        $has_local_files = intval($existing_record->has_local_files) === 1;
        if ($has_local_files) {
            $backup_dir = WP_CONTENT_DIR . '/wp-vault-backups/';
            $manifest_file = $backup_dir . 'backup-' . $backup_id . '-manifest.json';

            if (file_exists($manifest_file)) {
                $manifest_data = json_decode(file_get_contents($manifest_file), true);
                if ($manifest_data && isset($manifest_data['files'])) {
                    foreach ($manifest_data['files'] as $file) {
                        $file_path = $backup_dir . (is_array($file) ? $file['filename'] : $file);
                        if (file_exists($file_path)) {
                            wp_delete_file($file_path);
                        }
                    }
                }
                wp_delete_file($manifest_file);
            }

            // Also try to delete component files
            $patterns = array(
                $backup_dir . '{database,themes,plugins,uploads,wp-content}-' . $backup_id . '-*.tar.gz',
                $backup_dir . '{database,themes,plugins,uploads,wp-content}-' . $backup_id . '-*.sql.gz',
                $backup_dir . 'backup-' . $backup_id . '-*.tar.gz',
            );
            foreach ($patterns as $pattern) {
                $files = glob($pattern, GLOB_BRACE);
                if ($files && is_array($files)) {
                    foreach ($files as $file) {
                        if (file_exists($file)) {
                            wp_delete_file($file);
                        }
                    }
                }
            }
        }

        // Delete from backup_history
        $deleted = $wpdb->delete(
            $history_table,
            array('backup_id' => $backup_id),
            array('%s')
        );

        if ($deleted === false) {
            wp_send_json_error(array(
                'error' => 'Failed to remove backup from database: ' . ($wpdb->last_error ?: 'Unknown error'),
            ));
            return;
        }

        if ($deleted === 0) {
            wp_send_json_error(array(
                'error' => 'No backup record found to delete. It may have already been removed.',
            ));
            return;
        }

        wp_send_json_success(array(
            'message' => sprintf(esc_html__('Backup record removed from database (%d row(s) deleted)', 'wp-vault'), $deleted),
            'deleted_count' => $deleted,
        ));
    }

    /**
     * Sync remote backups from SaaS to local backup_history
     * 
     * @param array $remote_backups Array of backup objects from SaaS API
     * @return void
     */
    public function sync_remote_backups_to_history($remote_backups)
    {
        if (empty($remote_backups) || !is_array($remote_backups)) {
            return;
        }

        global $wpdb;
        $history_table = $wpdb->prefix . 'wp_vault_backup_history';
        $backup_dir = WP_CONTENT_DIR . '/wp-vault-backups/';

        foreach ($remote_backups as $backup) {
            $backup_id = isset($backup['backup_id']) ? $backup['backup_id'] : (isset($backup['id']) ? $backup['id'] : null);
            if (empty($backup_id)) {
                continue;
            }

            // Check if backup exists in history
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$history_table} WHERE backup_id = %s",
                $backup_id
            ));

            // Check if local files exist
            $manifest_file = $backup_dir . 'backup-' . $backup_id . '-manifest.json';
            $has_local_files = file_exists($manifest_file) ? 1 : 0;

            // Determine source
            $current_source = 'remote';
            if ($existing) {
                $existing_record = $wpdb->get_row($wpdb->prepare(
                    "SELECT has_local_files, source FROM {$history_table} WHERE backup_id = %s",
                    $backup_id
                ));
                if ($existing_record) {
                    $has_local_files = $existing_record->has_local_files ? 1 : $has_local_files;
                    // Update source to 'both' if local files exist, otherwise keep existing or set to 'remote'
                    $current_source = ($has_local_files && $existing_record->has_remote_files) ? 'both' :
                        ($has_local_files ? 'local' : ($existing_record->has_remote_files ? 'remote' : 'remote'));
                }
            }

            // Prepare backup data
            $backup_type = isset($backup['backup_type']) ? $backup['backup_type'] : 'full';
            $status = isset($backup['status']) ? $backup['status'] : 'unknown';
            $total_size = isset($backup['total_size_bytes']) ? intval($backup['total_size_bytes']) :
                (isset($backup['bytes']) ? intval($backup['bytes']) : 0);
            $started_at = isset($backup['started_at']) ? $backup['started_at'] :
                (isset($backup['created_at']) ? $backup['created_at'] : current_time('mysql'));
            $finished_at = isset($backup['finished_at']) ? $backup['finished_at'] :
                (isset($backup['completed_at']) ? $backup['completed_at'] : null);

            // Insert or update backup history
            $wpdb->replace(
                $history_table,
                array(
                    'backup_id' => $backup_id,
                    'job_type' => 'backup',
                    'backup_type' => $backup_type,
                    'status' => $status,
                    'total_size_bytes' => $total_size,
                    'progress_percent' => ($status === 'completed' || $status === 'success') ? 100 : 0,
                    'source' => $current_source,
                    'has_local_files' => $has_local_files,
                    'has_remote_files' => 1, // From SaaS, so definitely remote
                    'trigger_source' => isset($backup['trigger_source']) ? $backup['trigger_source'] : 'poll',
                    'schedule_id' => isset($backup['schedule_id']) ? $backup['schedule_id'] : null,
                    'started_at' => $started_at,
                    'finished_at' => $finished_at,
                    'error_message' => isset($backup['error_message']) ? $backup['error_message'] : null,
                ),
                array('%s', '%s', '%s', '%s', '%d', '%d', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s')
            );
        }
    }

    /**
     * Recursively delete a directory
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
        rmdir($dir);
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

        $backup_id = isset($_GET['backup_id']) ? sanitize_text_field(wp_unslash($_GET['backup_id'])) : '';

        if (empty($backup_id)) {
            wp_die('Backup ID not specified');
        }

        $backup_dir = WP_CONTENT_DIR . '/wp-vault-backups/';
        $manifest_file = $backup_dir . 'backup-' . $backup_id . '-manifest.json';

        $files_to_zip = array();

        // Try to get files from manifest first
        if (file_exists($manifest_file)) {
            $manifest_data = json_decode(file_get_contents($manifest_file), true);
            if ($manifest_data && !empty($manifest_data['files'])) {
                foreach ($manifest_data['files'] as $file) {
                    $file_path = $backup_dir . (is_array($file) ? $file['filename'] : $file);
                    if (file_exists($file_path)) {
                        $files_to_zip[] = array(
                            'path' => $file_path,
                            'name' => is_array($file) ? $file['filename'] : basename($file)
                        );
                    }
                }
                // Add manifest to ZIP
                $files_to_zip[] = array(
                    'path' => $manifest_file,
                    'name' => basename($manifest_file)
                );
            }
        }

        // If no manifest or no files found, look for component files or single backup file
        if (empty($files_to_zip)) {
            // Look for component files
            $component_files = glob($backup_dir . '{database,themes,plugins,uploads,wp-content}-' . $backup_id . '-*.tar.gz', GLOB_BRACE);
            foreach ($component_files as $file) {
                if (file_exists($file)) {
                    $files_to_zip[] = array(
                        'path' => $file,
                        'name' => basename($file)
                    );
                }
            }

            // If still no files, look for single backup file
            if (empty($files_to_zip)) {
                $single_file = glob($backup_dir . 'backup-' . $backup_id . '-*.tar.gz');
                if (!empty($single_file) && file_exists($single_file[0])) {
                    $files_to_zip[] = array(
                        'path' => $single_file[0],
                        'name' => basename($single_file[0])
                    );
                }
            }
        }

        if (empty($files_to_zip)) {
            wp_die('No backup files found');
        }

        // Create ZIP archive with all components
        $zip_filename = 'backup-' . $backup_id . '-' . gmdate('Y-m-d-His') . '.zip';
        $zip_path = $backup_dir . $zip_filename;

        // Remove existing ZIP if any
        if (file_exists($zip_path)) {
            wp_delete_file($zip_path);
        }

        // Use ZipArchive if available, otherwise use PclZip
        if (class_exists('ZipArchive')) {
            $zip = new \ZipArchive();
            if ($zip->open($zip_path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                wp_die('Failed to create ZIP archive');
            }

            // Add all files to ZIP
            foreach ($files_to_zip as $file_info) {
                $zip->addFile($file_info['path'], $file_info['name']);
            }

            $zip->close();
        } else {
            // Fallback to PclZip
            require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';
            $zip = new \PclZip($zip_path);

            // Add all files to ZIP
            foreach ($files_to_zip as $file_info) {
                $zip->add($file_info['path'], PCLZIP_OPT_REMOVE_PATH, $backup_dir);
            }
        }

        if (!file_exists($zip_path)) {
            wp_die('Failed to create ZIP archive');
        }

        // Send file for download
        // Note: readfile() is used here for file downloads as WP_Filesystem is not suitable for binary file streaming
        // This is a standard WordPress pattern for file downloads (see wp-admin/includes/file.php)
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . esc_attr($zip_filename) . '"');
        header('Content-Length: ' . filesize($zip_path));
        header('Pragma: no-cache');
        header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
        header('Expires: 0');

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Direct file download requires readfile() for proper streaming
        readfile($zip_path);

        // Clean up ZIP file after download
        wp_delete_file($zip_path);
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

        $filename = isset($_GET['file']) ? sanitize_file_name(wp_unslash($_GET['file'])) : '';

        if (empty($filename)) {
            wp_die('File not specified');
        }

        $backup_dir = WP_CONTENT_DIR . '/wp-vault-backups/';
        $file_path = $backup_dir . $filename;

        // Security: ensure file is in backup directory
        $real_file_path = realpath($file_path);
        $real_backup_dir = realpath($backup_dir);

        // Check if file exists first
        if (!file_exists($file_path)) {
            wp_die('File not found');
        }

        // Validate path is within backup directory
        if (!$real_file_path || !$real_backup_dir || strpos($real_file_path, $real_backup_dir) !== 0) {
            wp_die('Invalid file path');
        }

        // Send file for download
        // Note: readfile() is used here for file downloads as WP_Filesystem is not suitable for binary file streaming
        // This is a standard WordPress pattern for file downloads (see wp-admin/includes/file.php)
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . esc_attr($filename) . '"');
        header('Content-Length: ' . filesize($file_path));
        header('Pragma: no-cache');
        header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
        header('Expires: 0');

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Direct file download requires readfile() for proper streaming
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
        if (defined('WP_DEBUG') && WP_DEBUG) {
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

        // Update restore_history table
        $restore_history_table = $wpdb->prefix . 'wp_vault_restore_history';
        $restore_options_data = $restore_options;
        $backup_id_from_options = isset($restore_options_data['backup_id']) ? $restore_options_data['backup_id'] : null;
        $components = isset($restore_options_data['components']) ? $restore_options_data['components'] : array();

        // Ensure record exists before updating
        $existing_restore_history = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$restore_history_table} WHERE restore_id = %s",
            $restore_id
        ));

        if (!$existing_restore_history) {
            // Create record if it doesn't exist
            $wpdb->replace(
                $restore_history_table,
                array(
                    'restore_id' => $restore_id,
                    'backup_id' => $backup_id_from_options,
                    'status' => $final_status,
                    'progress_percent' => $final_progress,
                    'restore_mode' => $restore_mode,
                    'components' => json_encode($components),
                    'started_at' => current_time('mysql'),
                    'finished_at' => current_time('mysql'),
                    'error_message' => $result['success'] ? null : $result['error'],
                ),
                array('%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s')
            );
        } else {
            // Update existing record
            $wpdb->update(
                $restore_history_table,
                array(
                    'status' => $final_status,
                    'progress_percent' => $final_progress,
                    'finished_at' => current_time('mysql'),
                    'error_message' => $result['success'] ? null : $result['error'],
                ),
                array('restore_id' => $restore_id),
                array('%s', '%d', '%s', '%s'),
                array('%s')
            );
        }

        // Verify the update worked
        if (defined('WP_DEBUG') && WP_DEBUG) {
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

        $storage_type = isset($_POST['storage_type']) ? sanitize_text_field(wp_unslash($_POST['storage_type'])) : '';

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

        $storage_type = isset($_POST['storage_type']) ? sanitize_text_field(wp_unslash($_POST['storage_type'])) : '';

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
            /* translators: %d: number of temporary files cleaned up */
            'message' => sprintf(esc_html__('Cleaned up %d temporary files', 'wp-vault'), $cleaned),
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

        $log_file = isset($_POST['file']) ? sanitize_file_name(wp_unslash($_POST['file'])) : (isset($_POST['log_file']) ? sanitize_text_field(wp_unslash($_POST['log_file'])) : '');
        $lines_param = isset($_POST['lines']) ? intval(wp_unslash($_POST['lines'])) : 0;
        // Handle negative numbers for "last N lines" or 0/-1 for all lines
        // read_log uses: 0 = all lines, negative = last N lines, positive = first N lines
        $lines = $lines_param;
        $offset = isset($_POST['offset']) ? absint(wp_unslash($_POST['offset'])) : 0;

        if (empty($log_file)) {
            wp_send_json_error(array('message' => 'Log file required'));
            return;
        }

        // If it's just a filename, construct the full path
        $log_file_path = $log_file;
        if (strpos($log_file, WP_CONTENT_DIR) === false) {
            // It's just a filename, construct path to wp-vault-logs
            $log_dir = WP_CONTENT_DIR . DIRECTORY_SEPARATOR . 'wp-vault-logs' . DIRECTORY_SEPARATOR;
            $log_file_path = $log_dir . $log_file;
        }

        // Validate path is in wp-vault-logs directory for security
        $log_dir = WP_CONTENT_DIR . DIRECTORY_SEPARATOR . 'wp-vault-logs' . DIRECTORY_SEPARATOR;
        $real_log_path = realpath($log_file_path);
        $real_log_dir = realpath($log_dir);

        if (!$real_log_path || !$real_log_dir || strpos($real_log_path, $real_log_dir) !== 0) {
            wp_send_json_error(array('message' => 'Invalid log file path'));
            return;
        }

        if (!file_exists($log_file_path)) {
            wp_send_json_error(array('message' => 'Log file not found'));
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
     * AJAX: Get job logs
     */
    public function ajax_get_job_logs()
    {
        check_ajax_referer('wp-vault', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
            return;
        }

        $job_id = isset($_POST['job_id']) ? sanitize_text_field(wp_unslash($_POST['job_id'])) : '';

        if (empty($job_id)) {
            wp_send_json_error(array('message' => 'Job ID required'));
            return;
        }

        global $wpdb;
        $logs_table = $wpdb->prefix . 'wp_vault_job_logs';
        $jobs_table = $wpdb->prefix . 'wp_vault_jobs';

        // Get job logs
        $logs = array();
        if ($wpdb->get_var("SHOW TABLES LIKE '$logs_table'") == $logs_table) {
            $logs = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM $logs_table WHERE job_id = %s ORDER BY created_at ASC",
                    $job_id
                ),
                ARRAY_A
            );
        }

        // Also try to get log file path from job
        $job = $wpdb->get_row($wpdb->prepare("SELECT log_file_path FROM $jobs_table WHERE backup_id = %s", $job_id));
        if ($job && !empty($job->log_file_path) && file_exists($job->log_file_path)) {
            require_once WP_VAULT_PLUGIN_DIR . 'includes/class-wp-vault-log.php';
            $log_result = WP_Vault_Log::read_log($job->log_file_path, -200); // Last 200 lines
            if (!empty($log_result['content'])) {
                $log_lines = explode("\n", trim($log_result['content']));
                foreach ($log_lines as $line) {
                    if (empty(trim($line)))
                        continue;
                    if (preg_match('/^\[([^\]]+)\]\[([^\]]+)\]\s*(.+)$/', $line, $matches)) {
                        $logs[] = array(
                            'severity' => strtoupper($matches[2]),
                            'message' => $matches[3],
                            'created_at' => $matches[1],
                            'step' => '',
                        );
                    }
                }
            }
        }

        wp_send_json_success(array(
            'logs' => $logs,
            'job_id' => $job_id,
        ));
    }

    /**
     * AJAX: Cancel restore
     */
    public function ajax_cancel_restore()
    {
        check_ajax_referer('wp-vault', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
            return;
        }

        $restore_id = isset($_POST['restore_id']) ? sanitize_text_field(wp_unslash($_POST['restore_id'])) : '';

        if (empty($restore_id)) {
            wp_send_json_error(array('message' => 'Restore ID required'));
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'wp_vault_jobs';

        // Update restore status to cancelled
        $updated = $wpdb->update(
            $table,
            array(
                'status' => 'cancelled',
                'error_message' => 'Restore cancelled by user',
                'finished_at' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
            ),
            array('backup_id' => $restore_id, 'job_type' => 'restore'),
            array('%s', '%s', '%s', '%s'),
            array('%s', '%s')
        );

        if ($updated !== false) {
            wp_send_json_success(array(
                'message' => 'Restore cancelled successfully',
                'restore_id' => $restore_id,
            ));
        } else {
            wp_send_json_error(array('message' => 'Failed to cancel restore'));
        }
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

        $log_file = isset($_GET['file']) ? sanitize_file_name(wp_unslash($_GET['file'])) : (isset($_GET['log_file']) ? sanitize_text_field(wp_unslash($_GET['log_file'])) : '');

        if (empty($log_file)) {
            wp_die('Log file required');
            return;
        }

        // If it's just a filename, construct the full path
        $log_file_path = $log_file;
        if (strpos($log_file, WP_CONTENT_DIR) === false) {
            // It's just a filename, construct path to wp-vault-logs
            $log_dir = WP_CONTENT_DIR . DIRECTORY_SEPARATOR . 'wp-vault-logs' . DIRECTORY_SEPARATOR;
            $log_file_path = $log_dir . $log_file;
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
        // Note: readfile() is used here for file downloads as WP_Filesystem is not suitable for binary file streaming
        // This is a standard WordPress pattern for file downloads (see wp-admin/includes/file.php)
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . esc_attr(basename($log_file_path)) . '"');
        header('Content-Length: ' . filesize($log_file_path));
        header('Pragma: no-cache');
        header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
        header('Expires: 0');

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Direct file download requires readfile() for proper streaming
        readfile($log_file_path);
        exit;
    }

    /**
     * AJAX: Get storage configuration from SaaS
     */
    public function ajax_get_saas_storages()
    {
        check_ajax_referer('wp-vault', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }

        require_once WP_VAULT_PLUGIN_DIR . 'includes/class-wp-vault-api.php';
        $api = new WP_Vault_API();

        $result = $api->get_storage_config();

        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }

    /**
     * AJAX: Get download status (for progress polling)
     */
    public function ajax_get_download_status()
    {
        check_ajax_referer('wp-vault', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }

        // Close session to allow multiple status requests
        if (session_id()) {
            session_write_close();
        }

        $backup_id = isset($_POST['backup_id']) ? sanitize_text_field(wp_unslash($_POST['backup_id'])) : '';

        if (empty($backup_id)) {
            wp_send_json_error(array('error' => 'Backup ID not specified'));
        }

        $log_file = WP_CONTENT_DIR . '/wp-vault-logs/' . $backup_id . '_download_log.txt';

        // Debug logging
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[WP Vault] ajax_get_download_status: Checking log file: ' . $log_file);
        }

        if (!file_exists($log_file)) {
            wp_send_json_success(array(
                'progress' => 0,
                'message' => esc_html__('Starting download...', 'wp-vault'),
                'status' => 'running',
                'logs' => array(),
            ));
            return;
        }

        // Read last 100 lines of log file
        require_once WP_VAULT_PLUGIN_DIR . 'includes/class-wp-vault-log.php';
        $log_result = WP_Vault_Log::read_log($log_file, -100);
        $log_content = $log_result['content'] ?? '';

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[WP Vault] ajax_get_download_status: Read ' . strlen($log_content) . ' bytes from log');
        }

        // Parse log lines into structured format
        $logs = array();
        if (!empty($log_content)) {
            $lines = explode("\n", $log_content);
            foreach ($lines as $line) {
                $line = trim($line);
                if (
                    empty($line) ||
                    strpos($line, 'Log created:') === 0 ||
                    strpos($line, 'Type:') === 0 ||
                    strpos($line, 'Job ID:') === 0 ||
                    strpos($line, 'Server Info:') !== false
                ) {
                    continue;
                }

                if (preg_match('/^\[([^\]]+)\]\[([^\]]+)\]\s*(.+)$/', $line, $matches)) {
                    $logs[] = array(
                        'created_at' => $matches[1],
                        'severity' => strtoupper($matches[2]),
                        'message' => trim($matches[3]),
                    );
                }
            }
        }

        // Parse progress from log
        $progress = 0;
        $message = esc_html__('Processing download...', 'wp-vault');
        $status = 'running';

        // Check for completion
        if (strpos($log_content, '===== DOWNLOAD COMPLETE =====') !== false) {
            $status = 'completed';
            $progress = 100;
            $message = esc_html__('Download completed successfully!', 'wp-vault');
        } elseif (preg_match('/\[ERROR\]|Failed to|Error:/i', $log_content)) {
            foreach (array_reverse($logs) as $log_entry) {
                if ($log_entry['severity'] === 'ERROR' || strpos($log_entry['message'], 'Error:') !== false || strpos($log_entry['message'], 'Failed to') === 0) {
                    $message = $log_entry['message'];
                    $status = 'error';
                    break;
                }
            }
        }

        if ($status === 'running') {
            // Stage 1: Downloading chunks (0-30%)
            if (preg_match('/Downloaded (\d+)\/(\d+) chunk\(s\)? successfully/', $log_content, $matches)) {
                $progress = 30;
            } elseif (preg_match_all('/(?:Downloading|Downloaded) chunk (\d+)\/(\d+)/', $log_content, $matches_all, PREG_SET_ORDER)) {
                $last_match = end($matches_all);
                $count = (int) $last_match[1];
                $total = (int) $last_match[2];
                if ($total > 0) {
                    $progress = min(29, (int) (($count / $total) * 30));
                }
            }

            // Stage 2: Extracting chunks (30-50%)
            if (preg_match('/Extracted chunks successfully/', $log_content) || preg_match('/Extracted (\d+)\/(\d+) chunk\(s\)? successfully/', $log_content)) {
                $progress = 50;
            } elseif (preg_match_all('/(?:Extracting|Extracted) chunk (\d+)\/(\d+)/', $log_content, $matches_all, PREG_SET_ORDER)) {
                $last_match = end($matches_all);
                $count = (int) $last_match[1];
                $total = (int) $last_match[2];
                if ($total > 0) {
                    $progress = 30 + min(19, (int) (($count / $total) * 20));
                }
            }

            // Stage 3: Creating component archives (50-90%)
            if (preg_match('/Created all component archives/', $log_content)) {
                $progress = 90;
            } elseif (preg_match_all('/Creating archive for component: .* \((\d+)\/(\d+)\)/', $log_content, $matches_all, PREG_SET_ORDER)) {
                $last_match = end($matches_all);
                $count = (int) $last_match[1];
                $total = (int) $last_match[2];
                if ($total > 0) {
                    $progress = 50 + min(39, (int) (($count / $total) * 40));
                }
            } elseif (preg_match('/Created component archive:/', $log_content)) {
                if ($progress < 60)
                    $progress = 60;
            }

            // Stage 4: Finalizing (90-99%)
            if (strpos($log_content, 'Cleaning up temporary files') !== false || strpos($log_content, 'Created manifest.json') !== false) {
                $progress = 95;
            }

            // Update message to last INFO log
            if (!empty($logs)) {
                foreach (array_reverse($logs) as $log_entry) {
                    if ($log_entry['severity'] === 'INFO' && !empty($log_entry['message'])) {
                        if (strpos($log_entry['message'], '=====') === false && strpos($log_entry['message'], 'Backup ID:') === false) {
                            $message = $log_entry['message'];
                            break;
                        }
                    }
                }
            }
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[WP Vault] ajax_get_download_status: Progress: ' . $progress . '%, Message: ' . $message);
        }

        wp_send_json_success(array(
            'progress' => $progress,
            'message' => $message,
            'status' => $status,
            'logs' => $logs,
        ));
    }

    /**
     * AJAX: Set primary storage
     */
    public function ajax_set_primary_storage()
    {
        check_ajax_referer('wp-vault', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }

        $storage_id = isset($_POST['storage_id']) ? sanitize_text_field(wp_unslash($_POST['storage_id'])) : '';

        if (empty($storage_id)) {
            wp_send_json_error(array('message' => 'Storage ID is required'));
        }

        require_once WP_VAULT_PLUGIN_DIR . 'includes/class-wp-vault-api.php';
        $api = new WP_Vault_API();

        $result = $api->set_primary_storage($storage_id);

        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }
}
