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
                'error' => 'Not connected to Vault Cloud. Please connect your site first.',
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
     * Check connection status with SaaS
     * Similar to test_connection but with caching and status tracking
     * 
     * @param bool $force Force check even if recently checked
     * @return array Connection status result
     */
    public function check_connection($force = false)
    {
        if (!$this->site_id || !$this->site_token) {
            return array(
                'success' => false,
                'connected' => false,
                'error' => 'Not connected to Vault Cloud. Please connect your site first.',
            );
        }

        // Check if we should use cached status (unless forced)
        if (!$force) {
            $last_check = get_option('wpv_last_connection_check_at');
            $cached_status = get_option('wpv_connection_status');

            if ($last_check && $cached_status !== false) {
                $last_check_time = strtotime($last_check);
                $five_minutes_ago = time() - (5 * 60);

                // Return cached status if checked within last 5 minutes
                if ($last_check_time > $five_minutes_ago) {
                    return array(
                        'success' => true,
                        'connected' => $cached_status === 'connected',
                        'cached' => true,
                        'last_check' => $last_check,
                        'status' => $cached_status,
                    );
                }
            }
        }

        // Perform actual connection check by sending heartbeat
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

        $is_connected = false;
        $error_message = null;

        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
        } else {
            $status_code = wp_remote_retrieve_response_code($response);
            $body = json_decode(wp_remote_retrieve_body($response), true);

            if ($status_code === 200 && isset($body['status']) && $body['status'] === 'ok') {
                $is_connected = true;
                // Update heartbeat timestamp on success
                update_option('wpv_last_heartbeat_at', current_time('mysql'));
            } else {
                $error_message = isset($body['error']) ? $body['error'] : 'Connection test failed (HTTP ' . $status_code . ')';
            }
        }

        // Cache the connection status
        $status = $is_connected ? 'connected' : 'disconnected';
        update_option('wpv_connection_status', $status);
        update_option('wpv_last_connection_check_at', current_time('mysql'));

        return array(
            'success' => true,
            'connected' => $is_connected,
            'status' => $status,
            'last_check' => current_time('mysql'),
            'error' => $error_message,
        );
    }

    /**
     * Send heartbeat to SaaS
     */
    public function send_heartbeat()
    {
        if (!$this->site_id || !$this->site_token) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                // error_log('[WP Vault] Cannot send heartbeat: site_id or site_token missing');
                // error_log('[WP Vault] site_id: ' . ($this->site_id ?: 'NOT SET'));
                // error_log('[WP Vault] site_token: ' . ($this->site_token ? 'SET' : 'NOT SET'));
            }
            return;
        }

        $heartbeat_url = $this->api_endpoint . "/api/v1/sites/{$this->site_id}/heartbeat";

        if (defined('WP_DEBUG') && WP_DEBUG) {
            // error_log('[WP Vault] Sending heartbeat to: ' . $heartbeat_url);
        }

        $response = wp_remote_post($heartbeat_url, array(
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

        // Update local heartbeat timestamp on success
        if (is_wp_error($response)) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                // error_log('[WP Vault] Heartbeat send failed: ' . $response->get_error_message());
            }
        } else {
            $status_code = wp_remote_retrieve_response_code($response);
            $body = wp_remote_retrieve_body($response);

            if (defined('WP_DEBUG') && WP_DEBUG) {
                // error_log('[WP Vault] Heartbeat response status: ' . $status_code);
                // error_log('[WP Vault] Heartbeat response body: ' . $body);
            }

            if ($status_code === 200) {
                update_option('wpv_last_heartbeat_at', current_time('mysql'));
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    // error_log('[WP Vault] Heartbeat sent successfully, timestamp updated');
                }
            } else {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    // error_log('[WP Vault] Heartbeat failed with status: ' . $status_code);
                }
            }
        }
    }

    /**
     * Create backup job
     */
    public function create_backup($backup_type = 'full', $trigger = 'manual')
    {
        if (!$this->site_id || !$this->site_token) {
            return array(
                'success' => false,
                'error' => 'Not connected to Vault Cloud. Please configure WP Vault first.',
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
                'error' => 'Not connected to Vault Cloud',
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
     * Get download URLs for backup files from remote storage
     */
    public function get_backup_download_urls($backup_id)
    {
        if (!$this->site_id || !$this->site_token) {
            return array(
                'success' => false,
                'error' => 'Not connected to Vault Cloud',
            );
        }

        $url = $this->api_endpoint . "/api/v1/backups/{$backup_id}/download-urls?site_token=" . urlencode($this->site_token);

        $response = wp_remote_get($url, array('timeout' => 30));

        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'error' => $response->get_error_message(),
            );
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (isset($body['download_urls'])) {
            return array(
                'success' => true,
                'data' => $body,
            );
        }

        return array(
            'success' => false,
            'error' => isset($body['error']) ? $body['error'] : 'Failed to get download URLs',
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
                // error_log('[WP Vault] Failed to send log: ' . $response->get_error_message());
            }
        }
    }

    /**
     * Retrieve logs for a backup/job
     */
    public function get_backup_logs($backup_id, $limit = 100)
    {
        if (!$this->site_id || !$this->site_token) {
            return array('success' => false, 'error' => 'Not connected to Vault Cloud');
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
            return array('success' => false, 'error' => 'Not connected to Vault Cloud');
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
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    // error_log('[WP Vault] Update job API error: ' . $response->get_error_message() . ' URL: ' . $url);
                }
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
                    if (defined('WP_DEBUG') && WP_DEBUG) {
                        // error_log('[WP Vault] Update job API error (PATCH fallback): ' . $response->get_error_message() . ' URL: ' . $url);
                    }
                }
                return array('success' => false, 'error' => $response->get_error_message());
            }

            $status_code = wp_remote_retrieve_response_code($response);
            $response_body = json_decode(wp_remote_retrieve_body($response), true);
        }

        if ($status_code !== 200) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    // error_log('[WP Vault] Update job API failed: HTTP ' . $status_code . ' - ' . wp_remote_retrieve_body($response) . ' URL: ' . $url);
                }
            }
            return array('success' => false, 'error' => 'HTTP ' . $status_code . ': ' . ($response_body['error'] ?? 'Unknown error'));
        }

        return array('success' => true, 'data' => $response_body);
    }

    /**
     * Submit file inventory for incremental backup planning
     * 
     * @param array $files Array of file info: [{path, size, mtime, fingerprint}, ...]
     * @return array Response from API
     */
    public function submit_inventory($files)
    {
        if (!$this->site_id || !$this->site_token) {
            return array(
                'success' => false,
                'error' => 'Not connected to Vault Cloud. Please configure WP Vault first.',
            );
        }

        $host_class = WP_Vault_Host_Detector::get_host_class();

        $response = wp_remote_post($this->api_endpoint . '/api/v1/inventory', array(
            'headers' => array('Content-Type' => 'application/json'),
            'body' => json_encode(array(
                'site_id' => $this->site_id,
                'site_token' => $this->site_token,
                'host_class' => $host_class,
                'files' => $files
            )),
            'timeout' => 60, // Longer timeout for large inventories
        ));

        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'error' => $response->get_error_message(),
            );
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (isset($body['received']) && $body['received'] === true) {
            return array(
                'success' => true,
                'data' => $body,
            );
        }

        return array(
            'success' => false,
            'error' => isset($body['error']) ? $body['error'] : 'Inventory submission failed',
        );
    }

    /**
     * Commit snapshot after upload
     * 
     * @param string $snapshot_id Snapshot ID
     * @param array $objects Uploaded objects
     * @return array Response from API
     */
    public function commit_snapshot($snapshot_id, $objects)
    {
        if (!$this->site_id || !$this->site_token) {
            return array(
                'success' => false,
                'error' => 'Not connected to Vault Cloud. Please configure WP Vault first.',
            );
        }

        $response = wp_remote_post($this->api_endpoint . "/api/v1/snapshots/{$snapshot_id}/commit", array(
            'headers' => array('Content-Type' => 'application/json'),
            'body' => json_encode(array(
                'site_token' => $this->site_token,
                'objects' => $objects
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

        if (isset($body['status']) && $body['status'] === 'committed') {
            return array(
                'success' => true,
                'data' => $body,
            );
        }

        return array(
            'success' => false,
            'error' => isset($body['error']) ? $body['error'] : 'Snapshot commit failed',
        );
    }

    /**
     * Get restore plan for incremental restore
     * 
     * @param string $snapshot_id Snapshot ID to restore
     * @param string $restore_mode Restore mode
     * @return array Restore plan
     */
    public function get_restore_plan($snapshot_id, $restore_mode = 'full')
    {
        if (!$this->site_id || !$this->site_token) {
            return array(
                'success' => false,
                'error' => 'Not connected to Vault Cloud. Please configure WP Vault first.',
            );
        }

        $response = wp_remote_post($this->api_endpoint . '/api/v1/restore-plan', array(
            'headers' => array('Content-Type' => 'application/json'),
            'body' => json_encode(array(
                'site_id' => $this->site_id,
                'site_token' => $this->site_token,
                'snapshot_id' => $snapshot_id,
                'restore_mode' => $restore_mode
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

        if (isset($body['restore_steps'])) {
            return array(
                'success' => true,
                'data' => $body,
            );
        }

        return array(
            'success' => false,
            'error' => isset($body['error']) ? $body['error'] : 'Failed to get restore plan',
        );
    }

    /**
     * Get API endpoint
     * 
     * @return string API endpoint URL
     */
    public function get_api_endpoint()
    {
        return $this->api_endpoint;
    }

    /**
     * Get disk free space in GB
     */
    private function get_disk_free_space()
    {
        $free = @disk_free_space(ABSPATH);
        return $free ? round($free / (1024 * 1024 * 1024), 2) : 0;
    }

    /**
     * Get pending backup jobs from SaaS (Hybrid Model - Pull)
     * 
     * @param string $site_id
     * @param string $site_token
     * @return array|WP_Error
     */
    public function get_pending_jobs($site_id, $site_token)
    {
        if (!$site_id || !$site_token) {
            return array('success' => false, 'error' => 'Not connected to Vault Cloud');
        }

        $url = $this->api_endpoint . "/api/v1/sites/{$site_id}/pending-jobs?site_token=" . urlencode($site_token);

        $response = wp_remote_get($url, array(
            'timeout' => 15,
            'headers' => array('Content-Type' => 'application/json'),
        ));

        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'error' => $response->get_error_message()
            );
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (wp_remote_retrieve_response_code($response) !== 200) {
            return array(
                'success' => false,
                'error' => isset($data['error']) ? $data['error'] : 'Unknown error'
            );
        }

        return $data;
    }

    /**
     * Claim a pending job (prevents multiple instances from picking it up)
     * 
     * @param string $site_id
     * @param string $site_token
     * @param string $job_id
     * @return array|WP_Error
     */
    public function claim_pending_job($site_id, $site_token, $job_id)
    {
        if (!$site_id || !$site_token || !$job_id) {
            return array('success' => false, 'error' => 'Missing parameters');
        }

        $url = $this->api_endpoint . "/api/v1/sites/{$site_id}/pending-jobs/{$job_id}/claim?site_token=" . urlencode($site_token);

        $response = wp_remote_post($url, array(
            'timeout' => 10,
            'headers' => array('Content-Type' => 'application/json'),
        ));

        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'error' => $response->get_error_message()
            );
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (wp_remote_retrieve_response_code($response) !== 200) {
            return array(
                'success' => false,
                'error' => isset($data['error']) ? $data['error'] : 'Failed to claim job'
            );
        }

        return $data;
    }

    /**
     * Get storage configuration from SaaS
     * 
     * @return array|WP_Error
     */
    public function get_storage_config()
    {
        if (!$this->site_id || !$this->site_token) {
            return array(
                'success' => false,
                'error' => 'Not connected to Vault Cloud. Please configure WP Vault first.',
            );
        }

        $url = $this->api_endpoint . "/api/v1/sites/{$this->site_id}/storage-config?site_token=" . urlencode($this->site_token);

        $response = wp_remote_get($url, array(
            'timeout' => 10,
            'headers' => array('Content-Type' => 'application/json'),
        ));

        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'error' => $response->get_error_message()
            );
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (wp_remote_retrieve_response_code($response) !== 200) {
            return array(
                'success' => false,
                'error' => isset($data['error']) ? $data['error'] : 'Failed to fetch storage configuration'
            );
        }

        return $data;
    }

    /**
     * Set primary storage for this site
     * 
     * @param string $storage_id
     * @return array|WP_Error
     */
    public function set_primary_storage($storage_id)
    {
        if (!$this->site_id || !$this->site_token) {
            return array(
                'success' => false,
                'error' => 'Not connected to Vault Cloud. Please configure WP Vault first.',
            );
        }

        $url = $this->api_endpoint . "/api/v1/sites/{$this->site_id}/primary-storage?site_token=" . urlencode($this->site_token);

        $response = wp_remote_post($url, array(
            'timeout' => 10,
            'headers' => array('Content-Type' => 'application/json'),
            'body' => json_encode(array(
                'storage_id' => $storage_id,
            )),
        ));

        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'error' => $response->get_error_message()
            );
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (wp_remote_retrieve_response_code($response) !== 200) {
            return array(
                'success' => false,
                'error' => isset($data['error']) ? $data['error'] : 'Failed to set primary storage'
            );
        }

        return $data;
    }
}
