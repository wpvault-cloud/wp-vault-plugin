<?php
/**
 * Plugin Activator
 * 
 * @package WP_Vault
 * @since 1.0.0
 */

namespace WP_Vault;

class WP_Vault_Activator
{
    /**
     * Run on plugin activation
     */
    public static function activate()
    {
        global $wpdb;

        // Flush rewrite rules to ensure REST API routes are registered
        // This is critical for REST API to work
        flush_rewrite_rules(false);

        // Also ensure permalinks are enabled (REST API requires permalinks)
        // If permalinks are set to "Plain", REST API won't work
        $permalink_structure = get_option('permalink_structure');
        if (empty($permalink_structure)) {
            // Set a default permalink structure if none exists
            update_option('permalink_structure', '/%postname%/');
            flush_rewrite_rules(false);
        }

        // Create wp_wp_vault_settings table
        $table_settings = $wpdb->prefix . 'wp_vault_settings';
        $table_settings_escaped = esc_sql($table_settings);
        $charset_collate = $wpdb->get_charset_collate();

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- dbDelta requires table name in CREATE TABLE, table name is escaped
        $sql_settings = "CREATE TABLE IF NOT EXISTS {$table_settings_escaped} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            setting_key varchar(255) NOT NULL,
            setting_value longtext,
            PRIMARY KEY (id),
            UNIQUE KEY setting_key (setting_key)
        ) $charset_collate;";

        // Create wp_wp_vault_file_index table
        $table_files = $wpdb->prefix . 'wp_vault_file_index';
        $table_files_escaped = esc_sql($table_files);

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- dbDelta requires table name in CREATE TABLE, table name is escaped
        $sql_files = "CREATE TABLE IF NOT EXISTS {$table_files_escaped} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            file_path varchar(500) NOT NULL,
            file_hash varchar(64) NOT NULL,
            file_size bigint(20) NOT NULL,
            last_modified datetime NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY file_path (file_path),
            KEY file_hash (file_hash)
        ) $charset_collate;";

        // Create wp_wp_vault_jobs table
        $table_jobs = $wpdb->prefix . 'wp_vault_jobs';
        $table_jobs_escaped = esc_sql($table_jobs);

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- dbDelta requires table name in CREATE TABLE, table name is escaped
        $sql_jobs = "CREATE TABLE IF NOT EXISTS {$table_jobs_escaped} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            job_type varchar(50) NOT NULL,
            status varchar(50) DEFAULT 'pending',
            backup_id varchar(255),
            source_backup_id varchar(255) DEFAULT NULL,
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

        // Add total_size_bytes column if table exists but column doesn't
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema check for migration, table name is escaped
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Schema check for migration, table name is escaped
        $column_exists = $wpdb->get_results($wpdb->prepare("SHOW COLUMNS FROM {$table_jobs_escaped} LIKE %s", 'total_size_bytes'));
        if (empty($column_exists)) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Schema migration, table name is escaped
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Schema migration, table name is escaped
            $wpdb->query("ALTER TABLE {$table_jobs_escaped} ADD COLUMN total_size_bytes bigint(20) DEFAULT 0 AFTER progress_percent");
        }

        // Add updated_at column if table exists but column doesn't
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema check for migration, table name is escaped
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Schema check for migration, table name is escaped
        $updated_at_exists = $wpdb->get_results($wpdb->prepare("SHOW COLUMNS FROM {$table_jobs_escaped} LIKE %s", 'updated_at'));
        if (empty($updated_at_exists)) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Schema migration, table name is escaped
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Schema migration, table name is escaped
            $wpdb->query("ALTER TABLE {$table_jobs_escaped} ADD COLUMN updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at");
        }

        // Add log_file_path column if table exists but column doesn't
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema check for migration, table name is escaped
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Schema check for migration, table name is escaped
        $log_file_path_exists = $wpdb->get_results($wpdb->prepare("SHOW COLUMNS FROM {$table_jobs_escaped} LIKE %s", 'log_file_path'));
        if (empty($log_file_path_exists)) {
            // Check if error_message exists before using AFTER
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema check for migration, table name is escaped
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Schema check for migration, table name is escaped
            $error_message_exists = $wpdb->get_results($wpdb->prepare("SHOW COLUMNS FROM {$table_jobs_escaped} LIKE %s", 'error_message'));
            if (!empty($error_message_exists)) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Schema migration, table name is escaped
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Schema migration, table name is escaped
                $wpdb->query("ALTER TABLE {$table_jobs_escaped} ADD COLUMN log_file_path varchar(255) DEFAULT NULL AFTER error_message");
            } else {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Schema migration, table name is escaped
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Schema migration, table name is escaped
                $wpdb->query("ALTER TABLE {$table_jobs_escaped} ADD COLUMN log_file_path varchar(255) DEFAULT NULL");
            }
        }

        // Add cursor column for resumable jobs (cursor is a reserved keyword, must escape)
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema check for migration, table name is escaped
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Schema check for migration, table name is escaped
        $cursor_exists = $wpdb->get_results($wpdb->prepare("SHOW COLUMNS FROM {$table_jobs_escaped} LIKE %s", 'cursor'));
        if (empty($cursor_exists)) {
            // Check if log_file_path exists before using AFTER
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema check for migration, table name is escaped
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Schema check for migration, table name is escaped
            $log_file_path_check = $wpdb->get_results($wpdb->prepare("SHOW COLUMNS FROM {$table_jobs_escaped} LIKE %s", 'log_file_path'));
            if (!empty($log_file_path_check)) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Schema migration, table name is escaped
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Schema migration, table name is escaped
                $wpdb->query("ALTER TABLE {$table_jobs_escaped} ADD COLUMN `cursor` TEXT DEFAULT NULL AFTER log_file_path");
            } else {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Schema migration, table name is escaped
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Schema migration, table name is escaped
                $wpdb->query("ALTER TABLE {$table_jobs_escaped} ADD COLUMN `cursor` TEXT DEFAULT NULL");
            }
        }

        // Add phase column for job phase tracking
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema check for migration, table name is escaped
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Schema check for migration, table name is escaped
        $phase_exists = $wpdb->get_results($wpdb->prepare("SHOW COLUMNS FROM {$table_jobs_escaped} LIKE %s", 'phase'));
        if (empty($phase_exists)) {
            // Check if cursor exists before using AFTER
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema check for migration, table name is escaped
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Schema check for migration, table name is escaped
            $cursor_check = $wpdb->get_results($wpdb->prepare("SHOW COLUMNS FROM {$table_jobs_escaped} LIKE %s", 'cursor'));
            if (!empty($cursor_check)) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Schema migration, table name is escaped
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Schema migration, table name is escaped
                $wpdb->query("ALTER TABLE {$table_jobs_escaped} ADD COLUMN phase varchar(20) DEFAULT NULL AFTER `cursor` ");
            } else {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Schema migration, table name is escaped
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Schema migration, table name is escaped
                $wpdb->query("ALTER TABLE {$table_jobs_escaped} ADD COLUMN phase varchar(20) DEFAULT NULL");
            }
        }

        // Create wp_vault_backup_history table for tracking all backups
        $table_backup_history = $wpdb->prefix . 'wp_vault_backup_history';
        $table_backup_history_escaped = esc_sql($table_backup_history);
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

        // Create wp_vault_restore_history table for tracking all restores
        $table_restore_history = $wpdb->prefix . 'wp_vault_restore_history';
        $table_restore_history_escaped = esc_sql($table_restore_history);
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

        // Create wp_vault_media_optimization table for tracking image optimizations
        $table_media_optimization = $wpdb->prefix . 'wp_vault_media_optimization';
        $table_media_optimization_escaped = esc_sql($table_media_optimization);
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- dbDelta requires table name in CREATE TABLE, table name is escaped
        $sql_media_optimization = "CREATE TABLE IF NOT EXISTS {$table_media_optimization_escaped} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            attachment_id bigint(20) NOT NULL,
            original_size bigint(20) NOT NULL,
            compressed_size bigint(20) NOT NULL,
            compression_ratio decimal(5,2) DEFAULT 0.00,
            space_saved bigint(20) DEFAULT 0,
            compression_method varchar(50) DEFAULT 'php_native',
            original_mime_type varchar(100) DEFAULT NULL,
            output_mime_type varchar(100) DEFAULT NULL,
            webp_converted tinyint(1) DEFAULT 0,
            status varchar(20) DEFAULT 'pending',
            error_message text DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY attachment_id (attachment_id),
            KEY status (status),
            KEY compression_method (compression_method),
            KEY created_at (created_at)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql_settings);
        dbDelta($sql_files);
        dbDelta($sql_jobs);
        if (isset($sql_job_logs)) {
            dbDelta($sql_job_logs);
        }
        dbDelta($sql_backup_history);
        dbDelta($sql_restore_history);
        dbDelta($sql_media_optimization);

        // Set default options
        add_option('wpv_api_endpoint', 'https://wpvault.cloud');
        add_option('wpv_storage_type', 'gcs'); // Default to WP Vault Cloud
        add_option('wpv_primary_storage_type', 'gcs'); // Set GCS as primary storage
        add_option('wpv_backup_schedule', 'daily');
        add_option('wpv_incremental_enabled', 0);

        // Do NOT set default compression mode - user must select it
        // Set transient for activation redirect
        set_transient('wpv_activation_redirect', true, 30);

        // Schedule heartbeat
        if (!wp_next_scheduled('wpv_heartbeat')) {
            wp_schedule_event(time(), 'twicedaily', 'wpv_heartbeat');
        }
    }
}
