<?php
/**
 * Google Cloud Storage Adapter (WP Vault Cloud)
 * 
 * Handles uploads to Google Cloud Storage via WP Vault SaaS API
 * This adapter uses the SaaS API to get signed upload URLs, then uploads directly to GCS
 *
 * @package WP_Vault
 * @since 1.0.0
 */

namespace WP_Vault\Storage\Adapters;

use WP_Vault\Storage\Storage_Adapter;
use WP_Vault\WP_Vault_API;

class GCS_Adapter implements Storage_Adapter
{
    private $config;
    private $api;
    private $api_endpoint;
    private $site_token;

    public function __construct($config)
    {
        $this->config = $config;
        $this->api_endpoint = isset($config['api_endpoint'])
            ? $config['api_endpoint']
            : get_option('wpv_api_endpoint', 'https://api.wpvault.cloud');
        $this->site_token = isset($config['site_token'])
            ? $config['site_token']
            : get_option('wpv_site_token', '');

        // Initialize API client
        require_once WP_VAULT_PLUGIN_DIR . 'includes/class-wp-vault-api.php';
        $this->api = new WP_Vault_API();
    }

    /**
     * Upload a file to GCS via signed URL
     */
    public function upload($local_path, $remote_path)
    {
        if (!file_exists($local_path)) {
            return array(
                'success' => false,
                'error' => 'Local file not found',
            );
        }

        try {
            // Extract backup_id and chunk_sequence from remote_path if it's a chunk
            // Format: backups/{tenant_id}/{site_id}/{backup_id}/chunk-{sequence}.tar.gz
            $backup_id = $this->extract_backup_id_from_path($remote_path);
            $chunk_sequence = $this->extract_chunk_sequence_from_path($remote_path);

            if (!$backup_id) {
                return array(
                    'success' => false,
                    'error' => 'Could not extract backup_id from remote path',
                );
            }

            // Get file size and checksum
            $file_size = filesize($local_path);
            $checksum = hash_file('sha256', $local_path);

            // Request signed upload URL from SaaS API
            $upload_url_response = $this->request_upload_url(
                $backup_id,
                $chunk_sequence,
                $file_size,
                $checksum
            );

            if (!$upload_url_response['success']) {
                return array(
                    'success' => false,
                    'error' => $upload_url_response['error'] ?? 'Failed to get upload URL',
                );
            }

            $upload_url = $upload_url_response['upload_url'];
            $chunk_id = $upload_url_response['chunk_id'];

            // Read file content
            $file_content = file_get_contents($local_path);

            // Upload directly to GCS using signed URL
            $response = wp_remote_request($upload_url, array(
                'method' => 'PUT',
                'headers' => array(
                    'Content-Type' => 'application/gzip',
                    'Content-Length' => $file_size,
                ),
                'body' => $file_content,
                'timeout' => 300, // 5 minutes
            ));

            if (is_wp_error($response)) {
                return array(
                    'success' => false,
                    'error' => $response->get_error_message(),
                );
            }

            $status_code = wp_remote_retrieve_response_code($response);
            if ($status_code !== 200 && $status_code !== 201 && $status_code !== 204) {
                $body = wp_remote_retrieve_body($response);
                return array(
                    'success' => false,
                    'error' => "Upload failed with status code: {$status_code}. Response: {$body}",
                );
            }

            return array(
                'success' => true,
                'url' => $upload_url,
                'path' => $remote_path,
                'size' => $file_size,
                'chunk_id' => $chunk_id,
            );

        } catch (\Exception $e) {
            return array(
                'success' => false,
                'error' => $e->getMessage(),
            );
        }
    }

    /**
     * Download a file from GCS (via SaaS API signed download URL)
     */
    public function download($remote_path, $local_path)
    {
        try {
            // For downloads, we'd need a download URL endpoint in the SaaS API
            // For now, this is a placeholder
            return array(
                'success' => false,
                'error' => 'Download not yet implemented for GCS adapter',
            );
        } catch (\Exception $e) {
            return array(
                'success' => false,
                'error' => $e->getMessage(),
            );
        }
    }

    /**
     * Delete a file from GCS (via SaaS API)
     */
    public function delete($remote_path)
    {
        // Deletion would be handled by the SaaS API
        // For now, return success (actual deletion handled server-side)
        return array(
            'success' => true,
        );
    }

    /**
     * List files in storage directory
     */
    public function list_files($remote_dir = '')
    {
        // File listing would be handled by the SaaS API
        return array(
            'success' => false,
            'error' => 'File listing not yet implemented for GCS adapter',
        );
    }

    /**
     * Test connection to WP Vault Cloud
     */
    public function test_connection()
    {
        try {
            // Test by checking if we can reach the API
            $site_token = $this->site_token ?: get_option('wpv_site_token', '');
            $site_id = get_option('wpv_site_id', '');

            if (empty($site_token)) {
                return array(
                    'success' => false,
                    'error' => 'Site token not configured. Please register your site first.',
                );
            }

            if (empty($site_id)) {
                return array(
                    'success' => false,
                    'error' => 'Site ID not configured. Please register your site first.',
                );
            }

            // Ensure API client has the correct credentials
            if (!$this->api) {
                require_once WP_VAULT_PLUGIN_DIR . 'includes/class-wp-vault-api.php';
                $this->api = new WP_Vault_API();
            }

            // Use the existing API test connection method
            $result = $this->api->test_connection();

            if (isset($result['success']) && $result['success']) {
                return array(
                    'success' => true,
                    'message' => $result['message'] ?? 'Successfully connected to WP Vault Cloud',
                );
            } else {
                return array(
                    'success' => false,
                    'error' => $result['error'] ?? 'Connection test failed',
                );
            }
        } catch (\Exception $e) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                // error_log('[WP Vault GCS] Test connection error: ' . $e->getMessage());
            }
            return array(
                'success' => false,
                'error' => 'Connection test failed: ' . $e->getMessage(),
            );
        } catch (\Error $e) {
            // Catch fatal errors (like calling non-existent method)
            if (defined('WP_DEBUG') && WP_DEBUG) {
                // error_log('[WP Vault GCS] Test connection fatal error: ' . $e->getMessage());
            }
            return array(
                'success' => false,
                'error' => 'Connection test failed: ' . $e->getMessage(),
            );
        }
    }

    /**
     * Generate signed URL (delegates to SaaS API)
     */
    public function generate_signed_url($remote_path, $expiry_minutes = 60)
    {
        $backup_id = $this->extract_backup_id_from_path($remote_path);
        $chunk_sequence = $this->extract_chunk_sequence_from_path($remote_path);

        if (!$backup_id) {
            return array(
                'success' => false,
                'error' => 'Could not extract backup_id from remote path',
            );
        }

        $result = $this->request_upload_url($backup_id, $chunk_sequence);

        if ($result['success']) {
            return array(
                'success' => true,
                'url' => $result['upload_url'],
                'expires' => time() + ($expiry_minutes * 60),
            );
        }

        return array(
            'success' => false,
            'error' => $result['error'] ?? 'Failed to generate signed URL',
        );
    }

    /**
     * Get storage configuration
     */
    public function get_config()
    {
        return $this->config;
    }

    /**
     * Get storage type
     */
    public function get_type()
    {
        return 'gcs';
    }

    /**
     * Get storage name
     */
    public function get_name()
    {
        return 'WP Vault Cloud (Google Cloud Storage)';
    }

    /**
     * Request upload URL from SaaS API
     */
    private function request_upload_url($backup_id, $chunk_sequence, $size_bytes = null, $checksum = null)
    {
        $api_endpoint = $this->api_endpoint;
        $site_token = $this->site_token ?: get_option('wpv_site_token', '');

        if (empty($site_token)) {
            return array(
                'success' => false,
                'error' => 'Site token not configured',
            );
        }

        // Rewrite localhost for Docker compatibility
        if (strpos($api_endpoint, 'localhost') !== false) {
            $api_endpoint = str_replace('localhost', 'host.docker.internal', $api_endpoint);
        }

        $url = rtrim($api_endpoint, '/') . "/api/v1/backups/{$backup_id}/upload-url";

        $body = array(
            'site_token' => $site_token,
            'chunk_sequence' => $chunk_sequence,
        );

        if ($size_bytes !== null) {
            $body['size_bytes'] = $size_bytes;
        }

        if ($checksum !== null) {
            $body['checksum'] = $checksum;
        }

        $response = wp_remote_post($url, array(
            'headers' => array(
                'Content-Type' => 'application/json',
            ),
            'body' => json_encode($body),
            'timeout' => 30,
        ));

        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'error' => $response->get_error_message(),
            );
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        $data = json_decode($response_body, true);

        if ($status_code !== 200) {
            return array(
                'success' => false,
                'error' => $data['error'] ?? "API returned status code: {$status_code}",
            );
        }

        return array(
            'success' => true,
            'upload_url' => $data['upload_url'],
            'chunk_id' => $data['chunk_id'],
            'path' => $data['path'],
        );
    }

    /**
     * Extract backup_id from remote path
     * Format: backups/{tenant_id}/{site_id}/{backup_id}/chunk-{sequence}.tar.gz
     */
    private function extract_backup_id_from_path($remote_path)
    {
        // Match pattern: backups/{tenant_id}/{site_id}/{backup_id}/chunk-...
        // We need the third segment (backup_id), so match: backups/.../.../{backup_id}/
        if (preg_match('/backups\/[^\/]+\/[^\/]+\/([^\/]+)\//', $remote_path, $matches)) {
            return $matches[1];
        }
        // Fallback: try to extract from filename if path format is different
        if (preg_match('/backup-([a-zA-Z0-9_-]+)/', basename($remote_path), $matches)) {
            return $matches[1];
        }
        return null;
    }

    /**
     * Extract chunk sequence from remote path
     */
    private function extract_chunk_sequence_from_path($remote_path)
    {
        // Match pattern: chunk-{sequence}.tar.gz
        if (preg_match('/chunk-(\d+)\.tar\.gz/', $remote_path, $matches)) {
            return (int) $matches[1];
        }
        return 0;
    }
}
