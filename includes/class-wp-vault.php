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
            error_log('WP Vault: REST API routes registered via rest_api_init');
        }
    }

    /**
     * Fallback REST API route registration on init
     * Some hosting environments have issues with rest_api_init hook
     */
    public function maybe_register_rest_routes()
    {
        // Only register if REST API is available and we're in a REST API context
        if (defined('REST_REQUEST') && REST_REQUEST) {
            $this->register_rest_routes();
        }
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

        // Check and add updated_at column to wp_wp_vault_jobs if missing
        $table_jobs = $wpdb->prefix . 'wp_vault_jobs';
        $table_jobs_escaped = esc_sql($table_jobs);
        $updated_at_exists = $wpdb->get_results($wpdb->prepare("SHOW COLUMNS FROM {$table_jobs_escaped} LIKE %s", 'updated_at'));
        if (empty($updated_at_exists)) {
            // Table name is safe (from prefix), but we still use esc_sql for safety
            $wpdb->query("ALTER TABLE {$table_jobs_escaped} ADD COLUMN updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at");
        }

        // Check and add log_file_path column to wp_wp_vault_jobs if missing
        $log_file_path_exists = $wpdb->get_results($wpdb->prepare("SHOW COLUMNS FROM {$table_jobs_escaped} LIKE %s", 'log_file_path'));
        if (empty($log_file_path_exists)) {
            // Check if error_message exists before using AFTER
            $error_message_exists = $wpdb->get_results($wpdb->prepare("SHOW COLUMNS FROM {$table_jobs_escaped} LIKE %s", 'error_message'));
            if (!empty($error_message_exists)) {
                $wpdb->query("ALTER TABLE {$table_jobs_escaped} ADD COLUMN log_file_path varchar(255) DEFAULT NULL AFTER error_message");
            } else {
                $wpdb->query("ALTER TABLE {$table_jobs_escaped} ADD COLUMN log_file_path varchar(255) DEFAULT NULL");
            }
        }

        // Check and add cursor column for resumable jobs (cursor is a reserved keyword, must escape)
        $cursor_exists = $wpdb->get_results($wpdb->prepare("SHOW COLUMNS FROM {$table_jobs_escaped} LIKE %s", 'cursor'));
        if (empty($cursor_exists)) {
            // Check if log_file_path exists before using AFTER
            $log_file_path_check = $wpdb->get_results($wpdb->prepare("SHOW COLUMNS FROM {$table_jobs_escaped} LIKE %s", 'log_file_path'));
            if (!empty($log_file_path_check)) {
                $wpdb->query("ALTER TABLE {$table_jobs_escaped} ADD COLUMN `cursor` TEXT DEFAULT NULL AFTER log_file_path");
            } else {
                $wpdb->query("ALTER TABLE {$table_jobs_escaped} ADD COLUMN `cursor` TEXT DEFAULT NULL");
            }
        }

        // Check and add phase column for job phase tracking
        $phase_exists = $wpdb->get_results($wpdb->prepare("SHOW COLUMNS FROM {$table_jobs_escaped} LIKE %s", 'phase'));
        if (empty($phase_exists)) {
            // Check if cursor exists before using AFTER
            $cursor_check = $wpdb->get_results($wpdb->prepare("SHOW COLUMNS FROM {$table_jobs_escaped} LIKE %s", 'cursor'));
            if (!empty($cursor_check)) {
                $wpdb->query("ALTER TABLE {$table_jobs_escaped} ADD COLUMN phase varchar(20) DEFAULT NULL AFTER cursor");
            } else {
                $wpdb->query("ALTER TABLE {$table_jobs_escaped} ADD COLUMN phase varchar(20) DEFAULT NULL");
            }
        }

        // Drop wp_vault_job_logs table (replaced by file-based logging)
        // This is a migration - table is no longer needed
        $table_job_logs = $wpdb->prefix . 'wp_vault_job_logs';
        $table_job_logs_escaped = esc_sql($table_job_logs);
        $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_job_logs));
        if ($table_exists) {
            // Table name is safe (from prefix), but we use esc_sql for safety
            $wpdb->query("DROP TABLE IF EXISTS {$table_job_logs_escaped}");
        }

        // Check and add total_size_bytes and updated_at columns if missing
        $table_jobs = $wpdb->prefix . 'wp_vault_jobs';
        $table_jobs_escaped = esc_sql($table_jobs);
        $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_jobs));

        if ($table_exists) {
            // Add total_size_bytes column if missing
            $column_exists = $wpdb->get_results($wpdb->prepare("SHOW COLUMNS FROM {$table_jobs_escaped} LIKE %s", 'total_size_bytes'));
            if (empty($column_exists)) {
                $wpdb->query("ALTER TABLE {$table_jobs_escaped} ADD COLUMN total_size_bytes bigint(20) DEFAULT 0 AFTER progress_percent");
            }

            // Add updated_at column if missing
            $updated_at_exists = $wpdb->get_results($wpdb->prepare("SHOW COLUMNS FROM {$table_jobs_escaped} LIKE %s", 'updated_at'));
            if (empty($updated_at_exists)) {
                $wpdb->query("ALTER TABLE {$table_jobs_escaped} ADD COLUMN updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at");
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
                $column_exists = $wpdb->get_results($wpdb->prepare("SHOW COLUMNS FROM {$table_jobs_escaped} LIKE %s", $column_name));
                if (empty($column_exists)) {
                    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- ALTER TABLE statements cannot use placeholders
                    $wpdb->query($sql);
                }
            }
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
                ),
                array('%s', '%s', '%s', '%d', '%s')
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

            if (!$existing) {
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
            }

            // Update local job status to 'running' if it exists
            if ($existing) {
                $wpdb->update(
                    $table,
                    array('status' => 'running'),
                    array('backup_id' => $backup_id),
                    array('%s'),
                    array('%s')
                );
            }

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
                    // Delete all component files
                    foreach ($manifest_data['files'] as $file) {
                        $file_path = $backup_dir . $file['filename'];
                        if (file_exists($file_path)) {
                            wp_delete_file($file_path);
                            $deleted_count++;
                        }
                    }
                }
                // Delete manifest
                wp_delete_file($manifest_file);
                $deleted_count++;
            } else {
                // No manifest, delete all files matching backup_id pattern
                $pattern = $backup_dir . '*-' . $backup_id . '-*.tar.gz';
                $files = glob($pattern);
                foreach ($files as $file) {
                    wp_delete_file($file);
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

            if (wp_delete_file($backup_path)) {
                $deleted_count = 1;
            }
        } else {
            wp_send_json_error(array('error' => 'Backup ID or file not specified'));
        }

        if ($deleted_count > 0) {
            /* translators: %d: number of files deleted */
            wp_send_json_success(array('message' => sprintf(esc_html__('Deleted %d file(s)', 'wp-vault'), $deleted_count)));
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
        if (strpos(realpath($file_path), realpath($backup_dir)) !== 0) {
            wp_die('Invalid file path');
        }

        if (!file_exists($file_path)) {
            wp_die('File not found');
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

        $log_file_path = isset($_POST['log_file']) ? sanitize_text_field(wp_unslash($_POST['log_file'])) : '';
        $lines = isset($_POST['lines']) ? absint(wp_unslash($_POST['lines'])) : 100;
        $offset = isset($_POST['offset']) ? absint(wp_unslash($_POST['offset'])) : 0;

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

        $log_file_path = isset($_GET['log_file']) ? sanitize_text_field(wp_unslash($_GET['log_file'])) : '';

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
