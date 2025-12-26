<?php
/**
 * Fingerprinter
 * 
 * Generates file fingerprints using 64KB prefix + metadata
 *
 * @package WP_Vault
 * @since 1.0.0
 */

namespace WP_Vault;

class WP_Vault_Fingerprinter
{
    /**
     * Generate fingerprint for a file
     * 
     * Fingerprint = SHA1(filesize:filemtime:SHA1(first_64KB))
     * 
     * @param string $file_path Full path to file
     * @return string|false Fingerprint or false on error
     */
    public static function fingerprint($file_path)
    {
        if (!file_exists($file_path) || !is_readable($file_path)) {
            return false;
        }

        // Get file metadata
        $file_size = filesize($file_path);
        $file_mtime = filemtime($file_path);

        if ($file_size === false || $file_mtime === false) {
            return false;
        }

        // Read first 64KB
        $fh = @fopen($file_path, 'rb');
        if ($fh === false) {
            return false;
        }

        $data = @fread($fh, WPV_FINGERPRINT_BYTES);
        fclose($fh);

        if ($data === false) {
            return false;
        }

        // Generate fingerprint: SHA1(size:mtime:SHA1(first_64KB))
        $prefix_hash = sha1($data);
        $fingerprint = sha1($file_size . ':' . $file_mtime . ':' . $prefix_hash);

        return $fingerprint;
    }

    /**
     * Batch fingerprint files
     * 
     * @param array $files Array of file info: [['path' => string, 'size' => int, 'mtime' => int], ...]
     * @param int $time_budget Time budget in seconds
     * @return array ['files' => array with fingerprint added, 'processed' => int, 'time_used' => int]
     */
    public static function batch_fingerprint($files, $time_budget = WPV_TIME_BUDGET)
    {
        $start_time = time();
        $processed = 0;

        foreach ($files as &$file) {
            // Check time budget
            if ((time() - $start_time) >= $time_budget) {
                break;
            }

            // Skip if already has fingerprint
            if (isset($file['fingerprint'])) {
                $processed++;
                continue;
            }

            // Generate fingerprint
            $full_path = $file['full_path'] ?? null;
            if (!$full_path) {
                // Try to construct path
                $full_path = WP_CONTENT_DIR . '/' . ltrim($file['path'], '/');
            }

            $fingerprint = self::fingerprint($full_path);
            if ($fingerprint !== false) {
                $file['fingerprint'] = $fingerprint;
            }

            $processed++;
        }

        return [
            'files' => $files,
            'processed' => $processed,
            'time_used' => time() - $start_time
        ];
    }
}
