<?php
/**
 * Log Handler
 * 
 * File-based logging system similar to WPvivid
 * Stores logs in wp-content/wp-vault-logs/ instead of database
 *
 * @package WP_Vault
 * @since 1.0.0
 */

namespace WP_Vault;

class WP_Vault_Log
{
    private $log_file;
    private $log_file_handle;

    public function __construct()
    {
        $this->log_file_handle = false;
    }

    /**
     * Create a new log file
     * 
     * @param string $job_id Job ID (backup_id or restore_id)
     * @param string $job_type Type of job ('backup' or 'restore')
     * @return string Path to log file
     */
    public function create_log_file($job_id, $job_type = 'backup')
    {
        $log_dir = $this->get_log_directory();
        $this->log_file = $log_dir . $job_id . '_' . $job_type . '_log.txt';

        // Delete existing log if it exists (fresh start)
        if (file_exists($this->log_file)) {
            @unlink($this->log_file);
        }

        // Open file for appending
        $this->log_file_handle = @fopen($this->log_file, 'a');

        if ($this->log_file_handle) {
            // Write initial log header
            $offset = get_option('gmt_offset');
            $time = gmdate("Y-m-d H:i:s", time() + $offset * 60 * 60);
            $text = 'Log created: ' . $time . "\n";
            $text .= 'Type: ' . ucfirst($job_type) . "\n";
            $text .= 'Job ID: ' . $job_id . "\n";
            fwrite($this->log_file_handle, $text);

            // Write server information
            $this->write_server_info();
        }

        return $this->log_file;
    }

    /**
     * Open existing log file
     * 
     * @param string $log_file_path Full path to log file
     * @return string Path to log file
     */
    public function open_log_file($log_file_path)
    {
        $this->log_file = $log_file_path;

        if (file_exists($this->log_file)) {
            $this->log_file_handle = @fopen($this->log_file, 'a');
        }

        return $this->log_file;
    }

    /**
     * Write log entry
     * 
     * @param string $message Log message
     * @param string $level Log level (info, notice, warning, error)
     */
    public function write_log($message, $level = 'info')
    {
        if ($this->log_file_handle) {
            $offset = get_option('gmt_offset');
            $time = gmdate("Y-m-d H:i:s", time() + $offset * 60 * 60);
            $text = '[' . $time . '][' . strtoupper($level) . '] ' . $message . "\n";
            fwrite($this->log_file_handle, $text);
            fflush($this->log_file_handle); // Ensure immediate write
        }
    }

    /**
     * Close log file handle
     */
    public function close_file()
    {
        if ($this->log_file_handle) {
            fclose($this->log_file_handle);
            $this->log_file_handle = false;
        }
    }

    /**
     * Get log file path
     * 
     * @return string Log file path
     */
    public function get_log_file()
    {
        return $this->log_file;
    }

    /**
     * Get log directory path
     * Creates directory if it doesn't exist and adds protection
     * 
     * @return string Log directory path with trailing slash
     */
    public function get_log_directory()
    {
        $log_dir = WP_CONTENT_DIR . DIRECTORY_SEPARATOR . 'wp-vault-logs' . DIRECTORY_SEPARATOR;

        // Create directory if it doesn't exist
        if (!is_dir($log_dir)) {
            @wp_mkdir_p($log_dir);

            // Add .htaccess protection
            $htaccess_file = $log_dir . '.htaccess';
            if (!file_exists($htaccess_file)) {
                $htaccess_content = "<IfModule mod_rewrite.c>\r\nRewriteEngine On\r\nRewriteRule .* - [F,L]\r\n</IfModule>";
                @file_put_contents($htaccess_file, $htaccess_content);
            }

            // Add index.php to prevent directory listing
            $index_file = $log_dir . 'index.php';
            if (!file_exists($index_file)) {
                @file_put_contents($index_file, "<?php\n// Silence is golden.\n");
            }
        }

        return $log_dir;
    }

    /**
     * Write server information to log (similar to WPvivid)
     */
    private function write_server_info()
    {
        if (!$this->log_file_handle) {
            return;
        }

        global $wp_version, $wpdb;

        $sapi_type = php_sapi_name();
        $fcgi = (in_array($sapi_type, ['cgi-fcgi', 'fpm-fcgi'])) ? 'On' : 'Off';

        $max_execution_time = ini_get('max_execution_time');
        $memory_limit = ini_get('memory_limit');
        $memory_usage = size_format(memory_get_usage(true), 2);
        $memory_peak = size_format(memory_get_peak_usage(true), 2);

        $log = 'Server Info: ';
        $log .= 'FCGI: ' . $fcgi . ', ';
        $log .= 'Max Execution Time: ' . $max_execution_time . ', ';
        $log .= 'WP Version: ' . $wp_version . ', ';
        $log .= 'PHP Version: ' . phpversion() . ', ';
        $log .= 'DB Version: ' . $wpdb->db_version() . ', ';
        $log .= 'Memory Limit: ' . $memory_limit . ', ';
        $log .= 'Memory Usage: ' . $memory_usage . ', ';
        $log .= 'Peak Memory: ' . $memory_peak;

        // Check extensions
        $loaded_extensions = get_loaded_extensions();
        $extensions = array();
        $extensions[] = in_array('PDO', $loaded_extensions) ? 'PDO enabled' : 'PDO not enabled';
        $extensions[] = in_array('curl', $loaded_extensions) ? 'curl enabled' : 'curl not enabled';
        $extensions[] = in_array('zlib', $loaded_extensions) ? 'zlib enabled' : 'zlib not enabled';

        $log .= ', Extensions: ' . implode(', ', $extensions);
        $log .= ', Multisite: ' . (is_multisite() ? '1' : '0');

        $offset = get_option('gmt_offset');
        $time = gmdate("Y-m-d H:i:s", time() + $offset * 60 * 60);
        $text = '[' . $time . '][NOTICE] ' . $log . "\n";
        fwrite($this->log_file_handle, $text);
    }

    /**
     * Read log file content
     * 
     * @param string $log_file_path Path to log file
     * @param int $lines Number of lines to read (0 = all, negative = last N lines)
     * @param int $offset Line offset for pagination
     * @return array Array with 'content' and 'total_lines'
     */
    public static function read_log($log_file_path, $lines = 0, $offset = 0)
    {
        if (!file_exists($log_file_path)) {
            return array(
                'content' => '',
                'total_lines' => 0,
                'offset' => 0
            );
        }

        // Validate path is in wp-vault-logs directory
        $log_dir = WP_CONTENT_DIR . DIRECTORY_SEPARATOR . 'wp-vault-logs' . DIRECTORY_SEPARATOR;
        $real_log_path = realpath($log_file_path);
        $real_log_dir = realpath($log_dir);

        if (!$real_log_path || !$real_log_dir || strpos($real_log_path, $real_log_dir) !== 0) {
            return array(
                'content' => '',
                'total_lines' => 0,
                'offset' => 0,
                'error' => 'Invalid log file path'
            );
        }

        $file_content = file_get_contents($log_file_path);
        $all_lines = explode("\n", $file_content);
        $total_lines = count($all_lines);

        // If reading last N lines
        if ($lines < 0) {
            $lines = abs($lines);
            $offset = max(0, $total_lines - $lines);
        }

        // Apply offset and limit
        if ($offset > 0) {
            $all_lines = array_slice($all_lines, $offset);
        }

        if ($lines > 0) {
            $all_lines = array_slice($all_lines, 0, $lines);
        }

        return array(
            'content' => implode("\n", $all_lines),
            'total_lines' => $total_lines,
            'offset' => $offset + count($all_lines)
        );
    }
}
