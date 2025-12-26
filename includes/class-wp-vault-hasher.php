<?php
/**
 * Hasher
 * 
 * Conditional SHA256 hashing with budget limits
 *
 * @package WP_Vault
 * @since 1.0.0
 */

namespace WP_Vault;

class WP_Vault_Hasher
{
    /**
     * Compute SHA256 hash of a file (streaming)
     * 
     * @param string $file_path Full path to file
     * @return string|false SHA256 hash or false on error
     */
    public static function sha256($file_path)
    {
        if (!file_exists($file_path) || !is_readable($file_path)) {
            return false;
        }

        // Check file size limit
        $file_size = filesize($file_path);
        if ($file_size > WPV_FULL_HASH_LIMIT) {
            return false; // File too large for full hash
        }

        // Initialize hash context
        $ctx = hash_init('sha256');
        if ($ctx === false) {
            return false;
        }

        // Stream file in chunks
        $fh = @fopen($file_path, 'rb');
        if ($fh === false) {
            return false;
        }

        $chunk_size = 8192; // 8KB chunks
        while (!feof($fh)) {
            $data = @fread($fh, $chunk_size);
            if ($data === false) {
                fclose($fh);
                return false;
            }
            hash_update($ctx, $data);
        }

        fclose($fh);

        return hash_final($ctx);
    }

    /**
     * Batch hash files with byte budget
     * 
     * @param array $files Array of file info with 'path' and 'size'
     * @param int $byte_budget Maximum bytes to hash in this run
     * @param int $time_budget Time budget in seconds
     * @return array ['files' => array with sha256 added, 'bytes_processed' => int, 'time_used' => int]
     */
    public static function batch_hash($files, $byte_budget = WPV_MAX_BYTES_PER_RUN, $time_budget = WPV_TIME_BUDGET)
    {
        $start_time = time();
        $bytes_processed = 0;
        $processed = 0;

        foreach ($files as &$file) {
            // Check time budget
            if ((time() - $start_time) >= $time_budget) {
                break;
            }

            // Check byte budget
            $file_size = isset($file['size']) ? $file['size'] : 0;
            if ($bytes_processed + $file_size > $byte_budget) {
                break;
            }

            // Skip if already has hash
            if (isset($file['sha256'])) {
                $processed++;
                continue;
            }

            // Skip if file too large
            if ($file_size > WPV_FULL_HASH_LIMIT) {
                $processed++;
                continue;
            }

            // Generate hash
            $full_path = $file['full_path'] ?? null;
            if (!$full_path) {
                // Try to construct path
                $full_path = WP_CONTENT_DIR . '/' . ltrim($file['path'], '/');
            }

            $sha256 = self::sha256($full_path);
            if ($sha256 !== false) {
                $file['sha256'] = $sha256;
                $bytes_processed += $file_size;
            }

            $processed++;
        }

        return [
            'files' => $files,
            'bytes_processed' => $bytes_processed,
            'time_used' => time() - $start_time,
            'processed' => $processed
        ];
    }
}
