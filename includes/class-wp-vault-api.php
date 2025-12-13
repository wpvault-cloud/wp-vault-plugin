<?php
/**
 * WP Vault API Client
 * 
 * Handles communication with WP-Vault SaaS backend
 *
 * @package WP_Vault
 * @since 1.0.0
 */

namespace WP_Vault;

class WP_Vault_API
{
    private $api_endpoint;
    private $site_id;
    private $site_token;

    public function __construct()
    {
        $this->api_endpoint = get_option('wpv_api_endpoint', 'http://host.docker.internal:3000');
        $this->site_id = get_option('wpv_site_id');
        $this->site_token = get_option('wpv_site_token');

        // If endpoint is set to localhost inside the container, rewrite to host.docker.internal
        if ($this->api_endpoint && strpos($this->api_endpoint, 'localhost') !== false) {
            $this->api_endpoint = str_replace('localhost', 'host.docker.internal', $this->api_endpoint);
        }
    }

    /**
     * Register this WordPress site with WP-Vault SaaS
     */
    public function register_site($admin_email)
    {
        $response = wp_remote_post($this->api_endpoint . '/api/v1/sites/register', array(
            'headers' => array('Content-Type' => 'application/json'),
            'body' => json_encode(array(
                'site_url' => get_site_url(),
                'admin_email' => $admin_email,
                'site_name' => get_bloginfo('name'),
                'wp_version' => get_bloginfo('version'),
                'php_version' => phpversion(),
            )),
            'timeout' => 30,
        ));

        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'error' => $response->get_error_message(),
            );
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (isset($body['site_id']) && isset($body['site_token'])) {
            update_option('wpv_site_id', $body['site_id']);
            update_option('wpv_site_token', $body['site_token']);
            update_option('wpv_api_endpoint', $body['api_endpoint']);

            $this->site_id = $body['site_id'];
            $this->site_token = $body['site_token'];

            return array(
                'success' => true,
                'data' => $body,
            );
        }

        return array(
            'success' => false,
            'error' => isset($body['error']) ? $body['error'] : 'Registration failed',
        );
    }

    /**
     * Test connection to SaaS API
     */
    public function test_connection()
    {
        if (!$this->site_id || !$this->site_token) {
            return array(
                'success' => false,
                'error' => 'Site not registered. Please register your site first.',
            );
        }

        // Test by sending a heartbeat and checking the response
        $response = wp_remote_post($this->api_endpoint . "/api/v1/sites/{$this->site_id}/heartbeat", array(
            'headers' => array('Content-Type' => 'application/json'),
            'body' => json_encode(array(
                'site_token' => $this->site_token,
                'wp_version' => get_bloginfo('version'),
                'php_version' => phpversion(),
                'plugin_version' => WP_VAULT_VERSION,
                'disk_free_gb' => $this->get_disk_free_space(),
            )),
            'timeout' => 10,
        ));

        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'error' => $response->get_error_message(),
            );
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($status_code === 200 && isset($body['status']) && $body['status'] === 'ok') {
            return array(
                'success' => true,
                'message' => 'Successfully connected to WP Vault Cloud',
            );
        }

        return array(
            'success' => false,
            'error' => isset($body['error']) ? $body['error'] : 'Connection test failed (HTTP ' . $status_code . ')',
        );
    }

    /**
     * Send heartbeat to SaaS
     */
    public function send_heartbeat()
    {
        if (!$this->site_id || !$this->site_token) {
            return;
        }

        wp_remote_post($this->api_endpoint . "/api/v1/sites/{$this->site_id}/heartbeat", array(
            'headers' => array('Content-Type' => 'application/json'),
            'body' => json_encode(array(
                'site_token' => $this->site_token,
                'wp_version' => get_bloginfo('version'),
                'php_version' => phpversion(),
                'plugin_version' => WP_VAULT_VERSION,
                'disk_free_gb' => $this->get_disk_free_space(),
            )),
            'timeout' => 10,
        ));
    }

    /**
     * Create backup job
     */
    public function create_backup($backup_type = 'full', $trigger = 'manual')
    {
        if (!$this->site_id || !$this->site_token) {
            return array(
                'success' => false,
                'error' => 'Site not registered. Please configure WP Vault first.',
            );
        }

        $response = wp_remote_post($this->api_endpoint . "/api/v1/sites/{$this->site_id}/backups", array(
            'headers' => array('Content-Type' => 'application/json'),
            'body' => json_encode(array(
                'site_token' => $this->site_token,
                'backup_type' => $backup_type,
                'trigger' => $trigger,
            )),
            'timeout' => 30,
        ));

        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'error' => $response->get_error_message(),
            );
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (isset($body['backup_id'])) {
            return array(
                'success' => true,
                'data' => $body,
            );
        }

        return array(
            'success' => false,
            'error' => isset($body['error']) ? $body['error'] : 'Backup creation failed',
        );
    }

    /**
     * Get backups list
     */
    public function get_backups()
    {
        if (!$this->site_id || !$this->site_token) {
            return array(
                'success' => false,
                'error' => 'Site not registered',
            );
        }

        $response = wp_remote_get(
            $this->api_endpoint . "/api/v1/sites/{$this->site_id}/backups?site_token=" . urlencode($this->site_token),
            array('timeout' => 30)
        );

        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'error' => $response->get_error_message(),
            );
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (isset($body['backups'])) {
            return array(
                'success' => true,
                'data' => $body,
            );
        }

        return array(
            'success' => false,
            'error' => 'Failed to retrieve backups',
        );
    }

    /**
     * Send a log entry to SaaS for a backup/job
     */
    public function send_log($backup_id, $payload)
    {
        if (!$this->site_id || !$this->site_token) {
            return;
        }

        $body = array_merge(
            array(
                'site_token' => $this->site_token,
            ),
            $payload
        );

        $response = wp_remote_post(
            $this->api_endpoint . "/api/v1/jobs/{$backup_id}/logs",
            array(
                'headers' => array('Content-Type' => 'application/json'),
                'body' => wp_json_encode($body),
                'timeout' => 8,
            )
        );

        if (is_wp_error($response)) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[WP Vault] Failed to send log: ' . $response->get_error_message());
            }
        }
    }

    /**
     * Retrieve logs for a backup/job
     */
    public function get_backup_logs($backup_id, $limit = 100)
    {
        if (!$this->site_id || !$this->site_token) {
            return array('success' => false, 'error' => 'Site not registered');
        }

        $url = add_query_arg(
            array(
                'site_token' => $this->site_token,
                'limit' => absint($limit),
            ),
            $this->api_endpoint . "/api/v1/jobs/{$backup_id}/logs"
        );

        $response = wp_remote_get($url, array('timeout' => 10));

        if (is_wp_error($response)) {
            return array('success' => false, 'error' => $response->get_error_message());
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (isset($body['logs'])) {
            return array('success' => true, 'logs' => $body['logs']);
        }

        return array('success' => false, 'error' => 'Unable to fetch logs');
    }

    /**
     * Update job status and size in SaaS
     */
    public function update_job_status($backup_id, $status, $size_bytes = null)
    {
        if (!$this->site_id || !$this->site_token) {
            return array('success' => false, 'error' => 'Site not registered');
        }

        $body = array(
            'site_token' => $this->site_token,
            'status' => $status,
        );

        if ($size_bytes !== null) {
            $body['total_size_bytes'] = $size_bytes;
        }

        // Try update endpoint first, fallback to status PATCH if 404
        $url = $this->api_endpoint . "/api/v1/jobs/{$backup_id}/update";

        $response = wp_remote_post(
            $url,
            array(
                'headers' => array('Content-Type' => 'application/json'),
                'body' => wp_json_encode($body),
                'timeout' => 10,
            )
        );

        if (is_wp_error($response)) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[WP Vault] Update job API error: ' . $response->get_error_message() . ' URL: ' . $url);
            }
            return array('success' => false, 'error' => $response->get_error_message());
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $response_body = json_decode(wp_remote_retrieve_body($response), true);

        // If update endpoint returns 404, try status PATCH endpoint instead
        if ($status_code === 404) {
            $url = $this->api_endpoint . "/api/v1/jobs/{$backup_id}/status";
            $response = wp_remote_request(
                $url,
                array(
                    'method' => 'PATCH',
                    'headers' => array('Content-Type' => 'application/json'),
                    'body' => wp_json_encode($body),
                    'timeout' => 10,
                )
            );

            if (is_wp_error($response)) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('[WP Vault] Update job API error (PATCH fallback): ' . $response->get_error_message() . ' URL: ' . $url);
                }
                return array('success' => false, 'error' => $response->get_error_message());
            }

            $status_code = wp_remote_retrieve_response_code($response);
            $response_body = json_decode(wp_remote_retrieve_body($response), true);
        }

        if ($status_code !== 200) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[WP Vault] Update job API failed: HTTP ' . $status_code . ' - ' . wp_remote_retrieve_body($response) . ' URL: ' . $url);
            }
            return array('success' => false, 'error' => 'HTTP ' . $status_code . ': ' . ($response_body['error'] ?? 'Unknown error'));
        }

        return array('success' => true, 'data' => $response_body);
    }

    /**
     * Get disk free space in GB
     */
    private function get_disk_free_space()
    {
        $free = @disk_free_space(ABSPATH);
        return $free ? round($free / (1024 * 1024 * 1024), 2) : 0;
    }
}
