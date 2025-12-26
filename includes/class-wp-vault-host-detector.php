<?php
/**
 * Host Capability Detector
 * 
 * Detects hosting environment capabilities to optimize backup behavior
 *
 * @package WP_Vault
 * @since 1.0.0
 */

namespace WP_Vault;

class WP_Vault_Host_Detector
{
    const CLASS_SHARED = 'shared';   // memory < 512MB OR max_exec < 60
    const CLASS_MANAGED = 'managed'; // memory >= 512MB
    const CLASS_VPS = 'vps';         // CLI available OR memory >= 1GB

    /**
     * Detect host capabilities
     * 
     * @return array Host capability information
     */
    public static function detect()
    {
        $memory_limit_mb = self::parse_size(ini_get('memory_limit'));
        $max_execution_time = (int) ini_get('max_execution_time');
        $can_exec = function_exists('exec');
        $is_cli = (PHP_SAPI === 'cli');

        return [
            'php_sapi' => PHP_SAPI,
            'memory_limit_mb' => $memory_limit_mb,
            'max_execution_time' => $max_execution_time,
            'can_exec' => $can_exec,
            'is_cli' => $is_cli,
            'disable_functions' => ini_get('disable_functions'),
            'host_class' => self::classify($memory_limit_mb, $max_execution_time, $can_exec, $is_cli)
        ];
    }

    /**
     * Classify host type
     * 
     * @param int $memory_limit_mb Memory limit in MB
     * @param int $max_execution_time Max execution time in seconds
     * @param bool $can_exec Whether exec() is available
     * @param bool $is_cli Whether running in CLI mode
     * @return string Host class
     */
    private static function classify($memory_limit_mb, $max_execution_time, $can_exec, $is_cli)
    {
        // VPS: CLI available OR memory >= 1GB
        if ($is_cli || $can_exec || $memory_limit_mb >= 1024) {
            return self::CLASS_VPS;
        }

        // Shared: memory < 512MB OR max_exec < 60
        if ($memory_limit_mb < 512 || $max_execution_time < 60) {
            return self::CLASS_SHARED;
        }

        // Managed: memory >= 512MB
        return self::CLASS_MANAGED;
    }

    /**
     * Parse PHP size string to MB
     * 
     * @param string $size Size string (e.g., "256M", "1G")
     * @return int Size in MB
     */
    private static function parse_size($size)
    {
        if (empty($size)) {
            return 128; // Default assumption
        }

        $size = trim($size);
        $last = strtolower($size[strlen($size) - 1]);
        $value = (int) $size;

        switch ($last) {
            case 'g':
                $value *= 1024;
                break;
            case 'm':
                // Already in MB
                break;
            case 'k':
                $value /= 1024;
                break;
        }

        return (int) $value;
    }

    /**
     * Get stored host class or detect and store
     * 
     * @return string Host class
     */
    public static function get_host_class()
    {
        $stored = get_option('wpv_host_class');
        if ($stored) {
            return $stored;
        }

        $capabilities = self::detect();
        $host_class = $capabilities['host_class'];
        update_option('wpv_host_class', $host_class);
        update_option('wpv_host_capabilities', $capabilities);

        return $host_class;
    }
}
