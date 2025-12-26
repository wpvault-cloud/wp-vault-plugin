<?php
/**
 * Storage Factory
 * 
 * Factory pattern for creating storage adapter instances
 * Enables easy addition of new storage backends
 *
 * @package WP_Vault
 * @since 1.0.0
 */

namespace WP_Vault\Storage;

class Storage_Factory
{
    /**
     * Create storage adapter instance based on type
     *
     * @param string $type Storage type ('gcs', 's3', 'google-drive', 'ftp', 'sftp', 'dropbox')
     * @param array  $config Storage configuration
     * @return Storage_Adapter Storage adapter instance
     * @throws \Exception If storage type is not supported
     */
    public static function create($type, $config)
    {
        switch ($type) {
            case 'gcs':
                require_once WP_VAULT_PLUGIN_DIR . 'includes/storage/adapters/class-gcs-adapter.php';
                return new Adapters\GCS_Adapter($config);

            case 's3':
            case 'wasabi':
            case 'backblaze':
            case 'minio':
                require_once WP_VAULT_PLUGIN_DIR . 'includes/storage/adapters/class-s3-adapter.php';
                return new Adapters\S3_Adapter($config);

            case 'google-drive':
                require_once WP_VAULT_PLUGIN_DIR . 'includes/storage/adapters/class-google-drive-adapter.php';
                return new Adapters\Google_Drive_Adapter($config);

            case 'ftp':
                require_once WP_VAULT_PLUGIN_DIR . 'includes/storage/adapters/class-ftp-adapter.php';
                return new Adapters\FTP_Adapter($config);

            case 'sftp':
                require_once WP_VAULT_PLUGIN_DIR . 'includes/storage/adapters/class-sftp-adapter.php';
                return new Adapters\SFTP_Adapter($config);

            case 'dropbox':
                require_once WP_VAULT_PLUGIN_DIR . 'includes/storage/adapters/class-dropbox-adapter.php';
                return new Adapters\Dropbox_Adapter($config);

            default:
                throw new \Exception(esc_html("Unsupported storage type: {$type}"));
        }
    }

    /**
     * Get list of available storage adapters
     *
     * @return array Array of storage types with labels
     */
    public static function get_available_adapters()
    {
        return array(
            'gcs' => array(
                'label' => 'Google Cloud Storage',
                'description' => 'WP-Vault Cloud (Default)',
                'icon' => 'cloud',
            ),
            's3' => array(
                'label' => 'Amazon S3 / Compatible',
                'description' => 'AWS S3, MinIO, Wasabi, Backblaze B2',
                'icon' => 'database',
            ),
            'google-drive' => array(
                'label' => 'Google Drive',
                'description' => 'Store backups in Google Drive',
                'icon' => 'google',
            ),
            'ftp' => array(
                'label' => 'FTP',
                'description' => 'Traditional FTP server',
                'icon' => 'admin-site',
            ),
            'sftp' => array(
                'label' => 'SFTP',
                'description' => 'Secure FTP over SSH',
                'icon' => 'lock',
            ),
            'dropbox' => array(
                'label' => 'Dropbox',
                'description' => 'Store backups in Dropbox (Coming Soon)',
                'icon' => 'dropbox',
                'disabled' => true,
            ),
        );
    }

    /**
     * Validate storage configuration
     *
     * @param string $type Storage type
     * @param array  $config Storage configuration
     * @return array {
     *     @type bool   $valid   Whether configuration is valid
     *     @type array  $errors  Array of error messages
     * }
     */
    public static function validate_config($type, $config)
    {
        $errors = array();

        switch ($type) {
            case 'gcs':
                if (empty($config['bucket'])) {
                    $errors[] = 'Bucket name is required';
                }
                if (empty($config['credentials'])) {
                    $errors[] = 'Service account credentials are required';
                }
                break;

            case 's3':
            case 'wasabi':
            case 'backblaze':
            case 'minio':
                if (empty($config['bucket'])) {
                    $errors[] = 'Bucket name is required';
                }
                if (empty($config['access_key'])) {
                    $errors[] = 'Access key is required';
                }
                if (empty($config['secret_key'])) {
                    $errors[] = 'Secret key is required';
                }
                if (empty($config['region']) && $type !== 'minio') {
                    $errors[] = 'Region is required';
                }
                break;

            case 'google-drive':
                if (empty($config['client_id']) || empty($config['client_secret'])) {
                    $errors[] = 'Google Drive OAuth credentials are required';
                }
                break;

            case 'ftp':
            case 'sftp':
                if (empty($config['host'])) {
                    $errors[] = 'Host is required';
                }
                if (empty($config['username'])) {
                    $errors[] = 'Username is required';
                }
                if (empty($config['password'])) {
                    $errors[] = 'Password is required';
                }
                break;
        }

        return array(
            'valid' => empty($errors),
            'errors' => $errors,
        );
    }
}
