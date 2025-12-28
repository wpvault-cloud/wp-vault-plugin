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

        // Register heartbeat trigger endpoint (for SaaS to trigger heartbeat)
        $heartbeat_result = register_rest_route('wp-vault/v1', '/heartbeat/trigger', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'trigger_heartbeat'),
            'permission_callback' => array(__CLASS__, 'check_permission'),
        ));

        // Debug: Log if registration failed
        if (defined('WP_DEBUG') && WP_DEBUG) {
            if ($result === false) {
                error_log('WP Vault: Failed to register REST API route /backup/trigger');
            } else {
                error_log('WP Vault: REST API route /backup/trigger registered successfully');
            }

            if ($heartbeat_result === false) {
                error_log('WP Vault: Failed to register REST API route /heartbeat/trigger');
            } else {
                error_log('WP Vault: REST API route /heartbeat/trigger registered successfully');
            }
        }
    }

    /**
     * Check permission for REST API requests
     * Uses Bearer token authentication with site_token
     */
    public static function check_permission($request)
    {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[WP Vault REST API] Permission check called for route: ' . $request->get_route());
        }

        // Get Authorization header
        $auth_header = $request->get_header('Authorization');

        if (!$auth_header) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[WP Vault REST API] Missing Authorization header');
            }
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
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[WP Vault REST API] Invalid authorization format');
            }
            return new \WP_Error(
                'invalid_auth',
                'Invalid authorization format. Use: Bearer {token}',
                array('status' => 401)
            );
        }

        // Verify token matches site_token
        $site_token = get_option('wpv_site_token');

        if (!$site_token || $token !== $site_token) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[WP Vault REST API] Invalid token. Expected: ' . ($site_token ? substr($site_token, 0, 10) . '...' : 'NOT SET') . ', Got: ' . substr($token, 0, 10) . '...');
            }
            return new \WP_Error(
                'invalid_token',
                'Invalid site token',
                array('status' => 401)
            );
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[WP Vault REST API] Permission granted');
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
        $trigger_source = $schedule_id ? 'schedule' : 'api';

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
                    'schedule_id' => $schedule_id,
                    'trigger_source' => $trigger_source,
                ),
                array('%s', '%s', '%s', '%d', '%s', '%s', '%s')
            );
        } else {
            // Update metadata if job already exists
            $wpdb->update(
                $table,
                array(
                    'schedule_id' => $schedule_id,
                    'trigger_source' => $trigger_source,
                ),
                array('backup_id' => $backup_id),
                array('%s', '%s'),
                array('%s')
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

    /**
     * REST API endpoint: Trigger heartbeat
     * 
     * POST /wp-json/wp-vault/v1/heartbeat/trigger
     * Headers: Authorization: Bearer {site_token}
     * 
     * This endpoint allows the SaaS to trigger the WordPress plugin to send a heartbeat
     * Used during manual connection checks to ensure fresh heartbeat data
     */
    public static function trigger_heartbeat($request)
    {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[WP Vault] Heartbeat trigger endpoint called');
        }

        require_once WP_VAULT_PLUGIN_DIR . 'includes/class-wp-vault-api.php';

        $api = new WP_Vault_API();
        $site_id = get_option('wpv_site_id');
        $api_endpoint = get_option('wpv_api_endpoint');

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[WP Vault] Site ID: ' . ($site_id ?: 'NOT SET'));
            error_log('[WP Vault] API Endpoint: ' . ($api_endpoint ?: 'NOT SET'));
        }

        // Send heartbeat to SaaS
        $api->send_heartbeat();

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[WP Vault] Heartbeat sent to SaaS');
        }

        // Update local heartbeat timestamp
        $timestamp = current_time('mysql');
        update_option('wpv_last_heartbeat_at', $timestamp);

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[WP Vault] Local heartbeat timestamp updated: ' . $timestamp);
        }

        return rest_ensure_response(array(
            'success' => true,
            'message' => 'Heartbeat triggered successfully',
            'timestamp' => $timestamp,
            'site_id' => $site_id,
        ));
    }
}
