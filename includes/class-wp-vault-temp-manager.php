<?php
/**
 * Temp File Manager
 * 
 * Centralized management of temporary files with automatic cleanup
 *
 * @package WP_Vault
 * @since 1.0.0
 */

namespace WP_Vault;

class WP_Vault_Temp_Manager
{
    private $temp_dir;
    private $registry_file;

    public function __construct()
    {
        $this->temp_dir = WP_CONTENT_DIR . '/wp-vault-temp/';
        $this->registry_file = $this->temp_dir . '.registry.json';

        // Ensure temp directory exists
        if (!file_exists($this->temp_dir)) {
            wp_mkdir_p($this->temp_dir);
            // Protect directory
            file_put_contents($this->temp_dir . '.htaccess', "deny from all\n");
            file_put_contents($this->temp_dir . 'index.php', "<?php\n// Silence is golden.\n");
        }
    }

    /**
     * Create a temporary file with tracking
     */
    public function create_temp_file($purpose, $extension = 'tmp')
    {
        $filename = uniqid('wpv_' . $purpose . '_', true) . '.' . $extension;
        $filepath = $this->temp_dir . $filename;

        // Register file
        $this->register_file($filepath, $purpose);

        return $filepath;
    }

    /**
     * Create a temporary directory
     */
    public function create_temp_dir($purpose)
    {
        $dirname = uniqid('wpv_' . $purpose . '_', true);
        $dirpath = $this->temp_dir . $dirname;

        if (!file_exists($dirpath)) {
            wp_mkdir_p($dirpath);
        }

        // Register directory
        $this->register_file($dirpath, $purpose, true);

        return $dirpath;
    }

    /**
     * Register a file/directory in the registry
     */
    private function register_file($path, $purpose, $is_dir = false)
    {
        $registry = $this->get_registry();
        $relative_path = str_replace($this->temp_dir, '', $path);

        $registry[$relative_path] = array(
            'path' => $path,
            'purpose' => $purpose,
            'is_dir' => $is_dir,
            'created_at' => time(),
            'size' => $is_dir ? 0 : (file_exists($path) ? filesize($path) : 0),
        );

        $this->save_registry($registry);
    }

    /**
     * Get registry of all temp files
     */
    private function get_registry()
    {
        if (!file_exists($this->registry_file)) {
            return array();
        }

        $content = file_get_contents($this->registry_file);
        $registry = json_decode($content, true);

        return is_array($registry) ? $registry : array();
    }

    /**
     * Save registry to file
     */
    private function save_registry($registry)
    {
        // Clean up non-existent files from registry
        foreach ($registry as $key => $entry) {
            if (!file_exists($entry['path'])) {
                unset($registry[$key]);
            }
        }

        file_put_contents($this->registry_file, json_encode($registry, JSON_PRETTY_PRINT));
    }

    /**
     * Clean up old files
     */
    public function cleanup_old_files($max_age = 3600)
    {
        $registry = $this->get_registry();
        $cleaned = 0;
        $current_time = time();

        foreach ($registry as $key => $entry) {
            $age = $current_time - $entry['created_at'];

            // Delete files older than max_age
            if ($age > $max_age && file_exists($entry['path'])) {
                if ($entry['is_dir']) {
                    $this->delete_directory($entry['path']);
                } else {
                    @unlink($entry['path']);
                }
                unset($registry[$key]);
                $cleaned++;
            }
        }

        $this->save_registry($registry);

        return $cleaned;
    }

    /**
     * Clean up files by purpose
     */
    public function cleanup_by_purpose($purpose)
    {
        $registry = $this->get_registry();
        $cleaned = 0;

        foreach ($registry as $key => $entry) {
            if ($entry['purpose'] === $purpose && file_exists($entry['path'])) {
                if ($entry['is_dir']) {
                    $this->delete_directory($entry['path']);
                } else {
                    @unlink($entry['path']);
                }
                unset($registry[$key]);
                $cleaned++;
            }
        }

        $this->save_registry($registry);

        return $cleaned;
    }

    /**
     * Check available disk space
     */
    public function check_disk_space($required_mb)
    {
        $required_bytes = $required_mb * 1024 * 1024;
        $available_bytes = disk_free_space($this->temp_dir);

        if ($available_bytes === false) {
            return array(
                'success' => false,
                'error' => 'Could not determine available disk space',
            );
        }

        return array(
            'success' => $available_bytes >= $required_bytes,
            'available_mb' => round($available_bytes / 1024 / 1024, 2),
            'required_mb' => $required_mb,
            'sufficient' => $available_bytes >= $required_bytes,
        );
    }

    /**
     * Get total size of temp files
     */
    public function get_total_size()
    {
        $registry = $this->get_registry();
        $total_size = 0;

        foreach ($registry as $entry) {
            if (file_exists($entry['path'])) {
                if ($entry['is_dir']) {
                    $total_size += $this->get_directory_size($entry['path']);
                } else {
                    $total_size += filesize($entry['path']);
                }
            }
        }

        return $total_size;
    }

    /**
     * Get directory size recursively
     */
    private function get_directory_size($dir)
    {
        $size = 0;
        if (!is_dir($dir)) {
            return 0;
        }

        $files = array_diff(scandir($dir), array('.', '..'));
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                $size += $this->get_directory_size($path);
            } else {
                $size += filesize($path);
            }
        }

        return $size;
    }

    /**
     * Delete directory recursively
     */
    private function delete_directory($dir)
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), array('.', '..'));
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                $this->delete_directory($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($dir);
    }

    /**
     * Clean up all temp files (use with caution)
     */
    public function cleanup_all()
    {
        $registry = $this->get_registry();
        $cleaned = 0;

        foreach ($registry as $key => $entry) {
            if (file_exists($entry['path'])) {
                if ($entry['is_dir']) {
                    $this->delete_directory($entry['path']);
                } else {
                    @unlink($entry['path']);
                }
                $cleaned++;
            }
        }

        // Clear registry
        if (file_exists($this->registry_file)) {
            @unlink($this->registry_file);
        }

        return $cleaned;
    }

    /**
     * Get temp directory path
     */
    public function get_temp_dir()
    {
        return $this->temp_dir;
    }
}
