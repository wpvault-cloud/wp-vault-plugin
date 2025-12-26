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
        $charset_collate = $wpdb->get_charset_collate();

        $sql_settings = "CREATE TABLE IF NOT EXISTS $table_settings (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            setting_key varchar(255) NOT NULL,
            setting_value longtext,
            PRIMARY KEY (id),
            UNIQUE KEY setting_key (setting_key)
        ) $charset_collate;";

        // Create wp_wp_vault_file_index table
        $table_files = $wpdb->prefix . 'wp_vault_file_index';

        $sql_files = "CREATE TABLE IF NOT EXISTS $table_files (
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

        $sql_jobs = "CREATE TABLE IF NOT EXISTS $table_jobs (
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

        // Add total_size_bytes column if table exists but column doesn't
        $column_exists = $wpdb->get_results("SHOW COLUMNS FROM $table_jobs LIKE 'total_size_bytes'");
        if (empty($column_exists)) {
            $wpdb->query("ALTER TABLE $table_jobs ADD COLUMN total_size_bytes bigint(20) DEFAULT 0 AFTER progress_percent");
        }

        // Add updated_at column if table exists but column doesn't
        $updated_at_exists = $wpdb->get_results("SHOW COLUMNS FROM $table_jobs LIKE 'updated_at'");
        if (empty($updated_at_exists)) {
            $wpdb->query("ALTER TABLE $table_jobs ADD COLUMN updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at");
        }

        // Add log_file_path column if table exists but column doesn't
        $log_file_path_exists = $wpdb->get_results("SHOW COLUMNS FROM $table_jobs LIKE 'log_file_path'");
        if (empty($log_file_path_exists)) {
            // Check if error_message exists before using AFTER
            $error_message_exists = $wpdb->get_results("SHOW COLUMNS FROM $table_jobs LIKE 'error_message'");
            if (!empty($error_message_exists)) {
                $wpdb->query("ALTER TABLE $table_jobs ADD COLUMN log_file_path varchar(255) DEFAULT NULL AFTER error_message");
            } else {
                $wpdb->query("ALTER TABLE $table_jobs ADD COLUMN log_file_path varchar(255) DEFAULT NULL");
            }
        }

        // Add cursor column for resumable jobs (cursor is a reserved keyword, must escape)
        $cursor_exists = $wpdb->get_results("SHOW COLUMNS FROM $table_jobs LIKE 'cursor'");
        if (empty($cursor_exists)) {
            // Check if log_file_path exists before using AFTER
            $log_file_path_check = $wpdb->get_results("SHOW COLUMNS FROM $table_jobs LIKE 'log_file_path'");
            if (!empty($log_file_path_check)) {
                $wpdb->query("ALTER TABLE $table_jobs ADD COLUMN `cursor` TEXT DEFAULT NULL AFTER log_file_path");
            } else {
                $wpdb->query("ALTER TABLE $table_jobs ADD COLUMN `cursor` TEXT DEFAULT NULL");
            }
        }

        // Add phase column for job phase tracking
        $phase_exists = $wpdb->get_results("SHOW COLUMNS FROM $table_jobs LIKE 'phase'");
        if (empty($phase_exists)) {
            // Check if cursor exists before using AFTER
            $cursor_check = $wpdb->get_results("SHOW COLUMNS FROM $table_jobs LIKE 'cursor'");
            if (!empty($cursor_check)) {
                $wpdb->query("ALTER TABLE $table_jobs ADD COLUMN phase varchar(20) DEFAULT NULL AFTER cursor");
            } else {
                $wpdb->query("ALTER TABLE $table_jobs ADD COLUMN phase varchar(20) DEFAULT NULL");
            }
        }

        // Create wp_wp_vault_job_logs table (local cache of per-step logs)
        $table_job_logs = $wpdb->prefix . 'wp_vault_job_logs';
        $sql_job_logs = "CREATE TABLE IF NOT EXISTS $table_job_logs (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            backup_id varchar(255) NOT NULL,
            level varchar(20) DEFAULT 'INFO',
            step varchar(255),
            message text NOT NULL,
            percent int(3),
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY backup_id (backup_id),
            KEY created_at (created_at)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql_settings);
        dbDelta($sql_files);
        dbDelta($sql_jobs);
        dbDelta($sql_job_logs);

        // Set default options
        add_option('wpv_api_endpoint', 'http://localhost:3000');
        add_option('wpv_storage_type', 'gcs'); // Default to WP Vault Cloud
        add_option('wpv_primary_storage_type', 'gcs'); // Set GCS as primary storage
        add_option('wpv_backup_schedule', 'daily');
        add_option('wpv_incremental_enabled', 0);

        // Schedule heartbeat
        if (!wp_next_scheduled('wpv_heartbeat')) {
            wp_schedule_event(time(), 'twicedaily', 'wpv_heartbeat');
        }
    }
}
