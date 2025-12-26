<?php
/**
 * WP Vault REST API
 * 
 * Handles REST API endpoints for SaaS to trigger backups
 *
 * @package WP_Vault
 * @since 1.0.0
 */

namespace WP_Vault;

class WP_Vault_REST_API
{
    /**
     * Register REST API routes
     */
    public static function register_routes()
    {
        // Register backup trigger endpoint
        $result = register_rest_route('wp-vault/v1', '/backup/trigger', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'trigger_backup'),
            'permission_callback' => array(__CLASS__, 'check_permission'),
        ));

        // Debug: Log if registration failed
        if (defined('WP_DEBUG') && WP_DEBUG) {
            if ($result === false) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('WP Vault: Failed to register REST API route /backup/trigger');
                }
            } else {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('WP Vault: REST API route /backup/trigger registered successfully');
                }
            }
        }
    }

    /**
     * Check permission for REST API requests
     * Uses Bearer token authentication with site_token
     */
    public static function check_permission($request)
    {
        // Get Authorization header
        $auth_header = $request->get_header('Authorization');

        if (!$auth_header) {
            return new \WP_Error(
                'missing_auth',
                'Authorization header required',
                array('status' => 401)
            );
        }

        // Extract Bearer token
        if (preg_match('/Bearer\s+(.*)$/i', $auth_header, $matches)) {
            $token = $matches[1];
        } else {
            return new \WP_Error(
                'invalid_auth',
                'Invalid authorization format. Use: Bearer {token}',
                array('status' => 401)
            );
        }

        // Verify token matches site_token
        $site_token = get_option('wpv_site_token');

        if (!$site_token || $token !== $site_token) {
            return new \WP_Error(
                'invalid_token',
                'Invalid site token',
                array('status' => 401)
            );
        }

        return true;
    }

    /**
     * REST API endpoint: Trigger backup
     * 
     * POST /wp-json/wp-vault/v1/backup/trigger
     * Headers: Authorization: Bearer {site_token}
     * Body: {
     *   "backup_id": "string",
     *   "backup_type": "full|incremental|files|database",
     *   "incremental_strategy": "none|daily|weekly",
     *   "schedule_id": "string" (optional)
     * }
     */
    public static function trigger_backup($request)
    {
        $params = $request->get_json_params();

        $backup_id = isset($params['backup_id']) ? sanitize_text_field($params['backup_id']) : null;
        $backup_type = isset($params['backup_type']) ? sanitize_text_field($params['backup_type']) : 'full';
        $incremental_strategy = isset($params['incremental_strategy']) ? sanitize_text_field($params['incremental_strategy']) : 'none';
        $schedule_id = isset($params['schedule_id']) ? sanitize_text_field($params['schedule_id']) : null;

        if (!$backup_id) {
            return new \WP_Error(
                'missing_backup_id',
                'backup_id is required',
                array('status' => 400)
            );
        }

        // Create local job record if it doesn't exist
        global $wpdb;
        $table = $wpdb->prefix . 'wp_vault_jobs';

        // Check if job already exists
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

        // Trigger backup execution immediately
        // This will be picked up by the existing execute_backup method
        wp_schedule_single_event(time(), 'wpvault_execute_backup', array($backup_id, $backup_type));

        // Also trigger immediately via action hook
        do_action('wpvault_execute_backup', $backup_id, $backup_type);

        // Spawn cron to ensure it runs
        spawn_cron();

        return rest_ensure_response(array(
            'success' => true,
            'message' => 'Backup triggered successfully',
            'backup_id' => $backup_id,
            'backup_type' => $backup_type,
        ));
    }
}
