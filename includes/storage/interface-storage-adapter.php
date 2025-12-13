<?php
/**
 * Storage Adapter Interface
 * 
 * Defines the contract that all storage adapters must implement
 * This enables easy addition of new storage backends (Google Drive, FTP, SFTP, Dropbox, etc.)
 *
 * @package WP_Vault
 * @since 1.0.0
 */

namespace WP_Vault\Storage;

interface Storage_Adapter
{
    /**
     * Upload a file to storage
     *
     * @param string $local_path Local file path
     * @param string $remote_path Remote storage path
     * @return array {
     *     @type bool   $success Whether upload succeeded
     *     @type string $url     URL of uploaded file (if applicable)
     *     @type string $path    Remote path
     *     @type int    $size    File size in bytes
     *     @type string $error   Error message (if failed)
     * }
     */
    public function upload($local_path, $remote_path);

    /**
     * Download a file from storage
     *
     * @param string $remote_path Remote storage path
     * @param string $local_path  Local destination path
     * @return array {
     *     @type bool   $success Whether download succeeded
     *     @type string $path    Local file path
     *     @type int    $size    File size in bytes
     *     @type string $error   Error message (if failed)
     * }
     */
    public function download($remote_path, $local_path);

    /**
     * Delete a file from storage
     *
     * @param string $remote_path Remote storage path
     * @return array {
     *     @type bool   $success Whether deletion succeeded
     *     @type string $error   Error message (if failed)
     * }
     */
    public function delete($remote_path);

    /**
     * List files in storage directory
     *
     * @param string $remote_dir Remote directory path
     * @return array {
     *     @type bool  $success Whether list succeeded
     *     @type array $files   Array of file objects
     *     @type string $error  Error message (if failed)
     * }
     */
    public function list_files($remote_dir = '');

    /**
     * Test storage connection
     *
     * @return array {
     *     @type bool   $success Whether connection test succeeded
     *     @type string $message Connection status message
     *     @type string $error   Error message (if failed)
     * }
     */
    public function test_connection();

    /**
     * Generate a signed/temporary URL for direct upload (if supported)
     *
     * @param string $remote_path Remote storage path
     * @param int    $expiry_minutes URL expiry time in minutes (default: 60)
     * @return array {
     *     @type bool   $success Whether URL generation succeeded
     *     @type string $url     Signed URL for upload
     *     @type int    $expires Expiry timestamp
     *     @type string $error   Error message (if failed)
     * }
     */
    public function generate_signed_url($remote_path, $expiry_minutes = 60);

    /**
     * Get storage configuration details
     *
     * @return array Configuration array specific to this adapter
     */
    public function get_config();

    /**
     * Get storage type identifier
     *
     * @return string Storage type (e.g., 'gcs', 's3', 'google-drive', 'ftp', 'sftp')
     */
    public function get_type();

    /**
     * Get human-readable storage name
     *
     * @return string Storage name (e.g., 'Google Cloud Storage', 'Amazon S3', 'Google Drive')
     */
    public function get_name();
}
