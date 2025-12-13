<?php
/**
 * S3-Compatible Storage Adapter
 * 
 * Handles uploads to S3-compatible storage (AWS S3, MinIO, Wasabi, Backblaze B2)
 * This is the primary adapter for local testing with MinIO
 *
 * @package WP_Vault
 * @since 1.0.0
 */

namespace WP_Vault\Storage\Adapters;

use WP_Vault\Storage\Storage_Adapter;

class S3_Adapter implements Storage_Adapter
{
    private $config;
    private $endpoint;
    private $bucket;
    private $region;
    private $access_key;
    private $secret_key;

    public function __construct($config)
    {
        $this->config = $config;
        $this->endpoint = isset($config['endpoint']) ? $config['endpoint'] : 'https://s3.amazonaws.com';
        $this->bucket = $config['bucket'];
        $this->region = isset($config['region']) ? $config['region'] : 'us-east-1';
        $this->access_key = $config['access_key'];
        $this->secret_key = $config['secret_key'];
    }

    public function upload($local_path, $remote_path)
    {
        if (!file_exists($local_path)) {
            return array(
                'success' => false,
                'error' => 'Local file not found',
            );
        }

        try {
            // Read file contents
            $file_content = file_get_contents($local_path);
            $file_size = filesize($local_path);

            // Generate upload URL
            $url = $this->generate_s3_url($remote_path);

            // Create AWS signature v4
            $headers = $this->create_aws4_headers('PUT', $remote_path, $file_content);

            // Upload using wp_remote_request
            $response = wp_remote_request($url, array(
                'method' => 'PUT',
                'headers' => $headers,
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
            if ($status_code !== 200 && $status_code !== 201) {
                return array(
                    'success' => false,
                    'error' => "Upload failed with status code: {$status_code}",
                );
            }

            return array(
                'success' => true,
                'url' => $url,
                'path' => $remote_path,
                'size' => $file_size,
            );

        } catch (\Exception $e) {
            return array(
                'success' => false,
                'error' => $e->getMessage(),
            );
        }
    }

    public function download($remote_path, $local_path)
    {
        try {
            $url = $this->generate_s3_url($remote_path);
            $headers = $this->create_aws4_headers('GET', $remote_path, '');

            $response = wp_remote_get($url, array(
                'headers' => $headers,
                'timeout' => 300,
                'stream' => true,
                'filename' => $local_path,
            ));

            if (is_wp_error($response)) {
                return array(
                    'success' => false,
                    'error' => $response->get_error_message(),
                );
            }

            return array(
                'success' => true,
                'path' => $local_path,
                'size' => filesize($local_path),
            );

        } catch (\Exception $e) {
            return array(
                'success' => false,
                'error' => $e->getMessage(),
            );
        }
    }

    public function delete($remote_path)
    {
        try {
            $url = $this->generate_s3_url($remote_path);
            $headers = $this->create_aws4_headers('DELETE', $remote_path, '');

            $response = wp_remote_request($url, array(
                'method' => 'DELETE',
                'headers' => $headers,
            ));

            if (is_wp_error($response)) {
                return array(
                    'success' => false,
                    'error' => $response->get_error_message(),
                );
            }

            return array('success' => true);

        } catch (\Exception $e) {
            return array(
                'success' => false,
                'error' => $e->getMessage(),
            );
        }
    }

    public function list_files($remote_dir = '')
    {
        return array(
            'success' => false,
            'error' => 'List files not yet implemented',
        );
    }

    public function test_connection()
    {
        try {
            // Try to list bucket (HEAD request)
            $url = rtrim($this->endpoint, '/') . '/' . $this->bucket;
            $headers = $this->create_aws4_headers('HEAD', '', '');

            $response = wp_remote_head($url, array(
                'headers' => $headers,
            ));

            if (is_wp_error($response)) {
                return array(
                    'success' => false,
                    'message' => 'Connection failed',
                    'error' => $response->get_error_message(),
                );
            }

            $status_code = wp_remote_retrieve_response_code($response);
            if ($status_code === 200 || $status_code === 403) {
                // 403 means bucket exists but we don't have list permission (which is fine)
                return array(
                    'success' => true,
                    'message' => 'Connection successful',
                );
            }

            return array(
                'success' => false,
                'message' => 'Connection failed',
                'error' => "HTTP {$status_code}",
            );

        } catch (\Exception $e) {
            return array(
                'success' => false,
                'message' => 'Connection failed',
                'error' => $e->getMessage(),
            );
        }
    }

    public function generate_signed_url($remote_path, $expiry_minutes = 60)
    {
        // Signed URLs for S3 require AWS SDK or complex signature generation
        // For MVP, we'll use direct uploads
        return array(
            'success' => false,
            'error' => 'Signed URLs not yet implemented for S3',
        );
    }

    public function get_config()
    {
        return $this->config;
    }

    public function get_type()
    {
        return 's3';
    }

    public function get_name()
    {
        if (strpos($this->endpoint, 'minio') !== false) {
            return 'MinIO';
        } elseif (strpos($this->endpoint, 'wasabisys.com') !== false) {
            return 'Wasabi';
        } elseif (strpos($this->endpoint, 'backblazeb2.com') !== false) {
            return 'Backblaze B2';
        }
        return 'Amazon S3';
    }

    /**
     * Generate S3 URL
     */
    private function generate_s3_url($path)
    {
        return rtrim($this->endpoint, '/') . '/' . $this->bucket . '/' . ltrim($path, '/');
    }

    /**
     * Create AWS Signature V4 headers
     */
    private function create_aws4_headers($method, $path, $payload)
    {
        $date = gmdate('Ymd\THis\Z');
        $datestamp = gmdate('Ymd');

        $canonical_uri = '/' . $this->bucket . '/' . ltrim($path, '/');
        $canonical_querystring = '';
        $payload_hash = hash('sha256', $payload);

        $host = parse_url($this->endpoint, PHP_URL_HOST);
        $port = parse_url($this->endpoint, PHP_URL_PORT);
        if ($port) {
            $host .= ':' . $port;
        }

        $canonical_headers = "host:" . $host . "\n" .
            "x-amz-content-sha256:" . $payload_hash . "\n" .
            "x-amz-date:" . $date . "\n";

        $signed_headers = 'host;x-amz-content-sha256;x-amz-date';

        $canonical_request = $method . "\n" . $canonical_uri . "\n" . $canonical_querystring . "\n" .
            $canonical_headers . "\n" . $signed_headers . "\n" . $payload_hash;

        $algorithm = 'AWS4-HMAC-SHA256';
        $credential_scope = $datestamp . '/' . $this->region . '/s3/aws4_request';
        $string_to_sign = $algorithm . "\n" . $date . "\n" . $credential_scope . "\n" .
            hash('sha256', $canonical_request);

        $k_secret = 'AWS4' . $this->secret_key;
        $k_date = hash_hmac('sha256', $datestamp, $k_secret, true);
        $k_region = hash_hmac('sha256', $this->region, $k_date, true);
        $k_service = hash_hmac('sha256', 's3', $k_region, true);
        $k_signing = hash_hmac('sha256', 'aws4_request', $k_service, true);

        $signature = hash_hmac('sha256', $string_to_sign, $k_signing);

        $authorization_header = $algorithm . ' ' .
            'Credential=' . $this->access_key . '/' . $credential_scope . ', ' .
            'SignedHeaders=' . $signed_headers . ', ' .
            'Signature=' . $signature;

        return array(
            'Authorization' => $authorization_header,
            'x-amz-date' => $date,
            'x-amz-content-sha256' => $payload_hash,
            'Content-Type' => 'application/octet-stream',
        );
    }
}
