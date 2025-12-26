<?php
/**
 * File Scanner
 * 
 * Resumable file scanner for WordPress directories
 *
 * @package WP_Vault
 * @since 1.0.0
 */

namespace WP_Vault;

class WP_Vault_File_Scanner
{
    /**
     * Directories to scan
     */
    private $scan_directories = [
        'themes' => WP_CONTENT_DIR . '/themes',
        'plugins' => WP_CONTENT_DIR . '/plugins',
        'uploads' => WP_CONTENT_DIR . '/uploads',
        'wp-content' => WP_CONTENT_DIR
    ];

    /**
     * Scan files with cursor support
     * 
     * @param array|null $cursor Current cursor position
     * @return array ['files' => array, 'cursor' => array, 'completed' => bool]
     */
    public function scan($cursor = null)
    {
        $files = [];
        $start_time = time();
        $budget = WPV_TIME_BUDGET;
        $max_files = WPV_MAX_FILES_PER_RUN;

        // Initialize cursor if not provided
        if (!$cursor) {
            $cursor = [
                'current_directory' => 'themes',
                'current_path' => '',
                'file_offset' => 0,
                'directories_scanned' => []
            ];
        }

        // Get current directory to scan
        $current_dir_key = $cursor['current_directory'];
        if (!isset($this->scan_directories[$current_dir_key])) {
            // All directories scanned
            return [
                'files' => [],
                'cursor' => $cursor,
                'completed' => true
            ];
        }

        $base_path = $this->scan_directories[$current_dir_key];
        $current_path = $cursor['current_path'] ? $base_path . '/' . $cursor['current_path'] : $base_path;

        // Scan directory
        $result = $this->scan_directory($current_path, $base_path, $cursor, $files, $start_time, $budget, $max_files);

        // Update cursor
        $cursor = $result['cursor'];

        // Check if current directory is complete
        if ($result['directory_complete']) {
            $cursor['directories_scanned'][] = $current_dir_key;

            // Move to next directory
            $dir_keys = array_keys($this->scan_directories);
            $current_index = array_search($current_dir_key, $dir_keys);

            if ($current_index !== false && isset($dir_keys[$current_index + 1])) {
                $cursor['current_directory'] = $dir_keys[$current_index + 1];
                $cursor['current_path'] = '';
                $cursor['file_offset'] = 0;
            } else {
                // All directories scanned
                return [
                    'files' => $files,
                    'cursor' => $cursor,
                    'completed' => true
                ];
            }
        }

        return [
            'files' => $files,
            'cursor' => $cursor,
            'completed' => false
        ];
    }

    /**
     * Scan a single directory recursively
     * 
     * @param string $dir Directory path to scan
     * @param string $base_path Base path for relative paths
     * @param array $cursor Current cursor
     * @param array &$files Files array to populate
     * @param int $start_time Start time for budget
     * @param int $budget Time budget in seconds
     * @param int $max_files Maximum files to process
     * @return array ['cursor' => array, 'directory_complete' => bool]
     */
    private function scan_directory($dir, $base_path, $cursor, &$files, $start_time, $budget, $max_files)
    {
        if (!is_dir($dir) || !is_readable($dir)) {
            return [
                'cursor' => $cursor,
                'directory_complete' => true
            ];
        }

        // Skip certain directories
        $skip_dirs = ['.', '..', 'node_modules', '.git', 'vendor', 'cache', 'tmp'];
        $relative_path = str_replace($base_path . '/', '', $dir);

        // Get directory contents
        $items = @scandir($dir);
        if ($items === false) {
            return [
                'cursor' => $cursor,
                'directory_complete' => true
            ];
        }

        // Sort items for consistent ordering
        sort($items);

        // Process items starting from cursor offset
        $offset = isset($cursor['file_offset']) ? $cursor['file_offset'] : 0;
        $processed = 0;

        for ($i = $offset; $i < count($items); $i++) {
            // Check time budget
            if ((time() - $start_time) >= $budget) {
                $cursor['file_offset'] = $i;
                return [
                    'cursor' => $cursor,
                    'directory_complete' => false
                ];
            }

            // Check file limit
            if (count($files) >= $max_files) {
                $cursor['file_offset'] = $i;
                return [
                    'cursor' => $cursor,
                    'directory_complete' => false
                ];
            }

            $item = $items[$i];

            // Skip hidden/system files
            if (in_array($item, $skip_dirs) || $item[0] === '.') {
                continue;
            }

            $item_path = $dir . '/' . $item;
            $item_relative = $relative_path ? $relative_path . '/' . $item : $item;

            if (is_dir($item_path)) {
                // Recursively scan subdirectory
                $sub_result = $this->scan_directory(
                    $item_path,
                    $base_path,
                    ['current_path' => $item_relative, 'file_offset' => 0],
                    $files,
                    $start_time,
                    $budget,
                    $max_files
                );

                // If subdirectory not complete, update cursor and return
                if (!$sub_result['directory_complete']) {
                    $cursor['current_path'] = $item_relative;
                    $cursor['file_offset'] = $sub_result['cursor']['file_offset'];
                    return [
                        'cursor' => $cursor,
                        'directory_complete' => false
                    ];
                }
            } elseif (is_file($item_path) && is_readable($item_path)) {
                // Add file to list
                $stat = @stat($item_path);
                if ($stat !== false) {
                    $files[] = [
                        'path' => $item_relative,
                        'size' => $stat['size'],
                        'mtime' => $stat['mtime']
                    ];
                }
            }

            $processed++;
        }

        // Directory scan complete
        $cursor['file_offset'] = 0;
        $cursor['current_path'] = '';

        return [
            'cursor' => $cursor,
            'directory_complete' => true
        ];
    }

    /**
     * Get scan directories
     * 
     * @return array Scan directories
     */
    public function get_scan_directories()
    {
        return $this->scan_directories;
    }

    /**
     * Set scan directories
     * 
     * @param array $directories Directories to scan
     */
    public function set_scan_directories($directories)
    {
        $this->scan_directories = $directories;
    }
}
