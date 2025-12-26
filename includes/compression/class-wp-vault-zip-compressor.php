<?php
/**
 * ZIP Compressor (Legacy Mode)
 * 
 * PHP-based ZIP compression for maximum portability
 *
 * @package WP_Vault
 * @since 1.0.0
 */

namespace WP_Vault\Compression;

class WP_Vault_Zip_Compressor
{
    private $temp_dir;
    private $log_callback;

    public function __construct($temp_dir, $log_callback = null)
    {
        $this->temp_dir = $temp_dir;
        $this->log_callback = $log_callback;
    }

    /**
     * Create ZIP archive from files
     */
    public function create_archive($files, $archive_path, $split_size = 200 * 1024 * 1024)
    {
        // Check if ZipArchive is available
        if (class_exists('ZipArchive')) {
            return $this->create_with_ziparchive($files, $archive_path, $split_size);
        } else {
            // Fallback to PclZip
            return $this->create_with_pclzip($files, $archive_path, $split_size);
        }
    }

    /**
     * Create archive using ZipArchive (faster, native PHP extension)
     */
    private function create_with_ziparchive($files, $archive_path, $split_size)
    {
        $this->log('Using ZipArchive for compression...');

        $zip = new \ZipArchive();
        $result = $zip->open($archive_path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);

        if ($result !== true) {
            throw new \Exception(esc_html('Failed to create ZIP archive: ' . $result));
        }

        $batch_size = 100; // Process 100 files at a time
        $batch = array();
        $files_added = 0;
        $current_size = 0;
        $part_number = 1;
        $archives = array();

        foreach ($files as $file) {
            if (!file_exists($file['path'])) {
                continue;
            }

            $batch[] = $file;

            if (count($batch) >= $batch_size) {
                foreach ($batch as $batch_file) {
                    $zip->addFile($batch_file['path'], $batch_file['relative_path']);
                    $files_added++;
                    $current_size += $batch_file['size'];
                }
                $batch = array();

                // Check if we need to split
                if ($current_size >= $split_size) {
                    $zip->close();
                    $archives[] = $archive_path;

                    // Create next part
                    $part_number++;
                    $part_path = $this->get_part_path($archive_path, $part_number);
                    $zip = new \ZipArchive();
                    $zip->open($part_path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
                    $current_size = 0;
                }
            }
        }

        // Add remaining files
        if (!empty($batch)) {
            foreach ($batch as $batch_file) {
                $zip->addFile($batch_file['path'], $batch_file['relative_path']);
                $files_added++;
            }
        }

        $zip->close();
        $archives[] = $archive_path;

        $this->log('Created ZIP archive with ' . $files_added . ' files');

        return array(
            'archives' => $archives,
            'part_count' => count($archives),
            'total_files' => $files_added,
        );
    }

    /**
     * Create archive using PclZip (pure PHP, more portable)
     */
    private function create_with_pclzip($files, $archive_path, $split_size)
    {
        $this->log('Using PclZip for compression (fallback)...');

        // Check if PclZip is available
        if (!class_exists('PclZip')) {
            // Try to load PclZip library
            $pclzip_path = WP_VAULT_PLUGIN_DIR . 'includes/lib/pclzip.lib.php';
            if (file_exists($pclzip_path)) {
                require_once $pclzip_path;
            } else {
                throw new \Exception(esc_html('PclZip library not found. Please install PclZip or enable ZipArchive extension.'));
            }
        }

        $zip = new \PclZip($archive_path);

        // Prepare file list for PclZip
        $file_list = array();
        foreach ($files as $file) {
            if (file_exists($file['path'])) {
                $file_list[] = array(
                    PCLZIP_ATT_FILE_NAME => $file['path'],
                    PCLZIP_ATT_FILE_NEW_FULL_NAME => $file['relative_path'],
                );
            }
        }

        // Create archive with temp file option to reduce memory usage
        $result = $zip->create($file_list, PCLZIP_OPT_TEMP_FILE_ON);

        if ($result === 0) {
            throw new \Exception(esc_html('PclZip error: ' . $zip->errorInfo(true)));
        }

        $this->log('Created ZIP archive with PclZip: ' . count($file_list) . ' files');

        return array(
            'archives' => array($archive_path),
            'part_count' => 1,
            'total_files' => count($file_list),
        );
    }

    /**
     * Get part path for split archives
     */
    private function get_part_path($base_path, $part_number)
    {
        $path_info = pathinfo($base_path);
        $part_path = $path_info['dirname'] . '/' . $path_info['filename'] . '-part' . sprintf('%03d', $part_number) . '.' . $path_info['extension'];
        return $part_path;
    }

    /**
     * Log message
     */
    private function log($message)
    {
        if ($this->log_callback && is_callable($this->log_callback)) {
            call_user_func($this->log_callback, $message);
        } else {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[WP Vault ZIP Compressor] ' . $message);
            }
        }
    }
}
