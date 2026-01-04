<?php
/**
 * Compression Mode Capability Checker
 * 
 * Checks system capabilities for compression modes (fast/legacy)
 *
 * @package WP_Vault
 * @since 1.0.0
 */

namespace WP_Vault;

class WP_Vault_Compression_Checker
{
    /**
     * Check if a command exists in the system
     * 
     * @param string $command Command name to check
     * @return bool True if command exists, false otherwise
     */
    private static function command_exists($command)
    {
        // Check if exec is available
        if (!function_exists('exec')) {
            return false;
        }

        // Check if command is disabled
        $disabled_functions = ini_get('disable_functions');
        if (!empty($disabled_functions) && in_array('exec', explode(',', $disabled_functions), true)) {
            return false;
        }

        // Try to find the command
        $path = '';
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            // Windows
            exec("where $command 2>nul", $output, $return_var);
            if ($return_var === 0 && !empty($output)) {
                $path = trim($output[0]);
            }
        } else {
            // Unix-like systems
            exec("which $command 2>/dev/null", $output, $return_var);
            if ($return_var === 0 && !empty($output)) {
                $path = trim($output[0]);
            }
            // Fallback to command -v
            if (empty($path)) {
                exec("command -v $command 2>/dev/null", $output, $return_var);
                if ($return_var === 0 && !empty($output)) {
                    $path = trim($output[0]);
                }
            }
        }

        return !empty($path);
    }

    /**
     * Check if tar command is available
     * 
     * @return array Array with 'available' boolean and 'reason' string
     */
    public static function check_tar()
    {
        $available = self::command_exists('tar');
        return array(
            'available' => $available,
            'reason' => $available ? __('tar command found', 'wp-vault') : __('tar command not found', 'wp-vault'),
        );
    }

    /**
     * Check if gzip command is available
     * 
     * @return array Array with 'available' boolean and 'reason' string
     */
    public static function check_gzip()
    {
        $available = self::command_exists('gzip');
        return array(
            'available' => $available,
            'reason' => $available ? __('gzip command found', 'wp-vault') : __('gzip command not found', 'wp-vault'),
        );
    }

    /**
     * Check if PHP ZipArchive extension is available
     * 
     * @return array Array with 'available' boolean and 'reason' string
     */
    public static function check_ziparchive()
    {
        $available = class_exists('ZipArchive') || extension_loaded('zip');
        return array(
            'available' => $available,
            'reason' => $available ? __('PHP ZipArchive extension available', 'wp-vault') : __('PHP ZipArchive extension not available', 'wp-vault'),
        );
    }

    /**
     * Check if fast mode (tar + gzip) is available
     * 
     * @return array Array with availability status and details
     */
    public static function check_fast_mode()
    {
        $tar_check = self::check_tar();
        $gzip_check = self::check_gzip();

        $available = $tar_check['available'] && $gzip_check['available'];

        $requirements = array();
        $requirements[] = array(
            'name' => __('tar command', 'wp-vault'),
            'available' => $tar_check['available'],
            'reason' => $tar_check['reason'],
        );
        $requirements[] = array(
            'name' => __('gzip command', 'wp-vault'),
            'available' => $gzip_check['available'],
            'reason' => $gzip_check['reason'],
        );

        return array(
            'available' => $available,
            'requirements' => $requirements,
            'reason' => $available
                ? __('Fast mode is available. tar and gzip commands are found.', 'wp-vault')
                : __('Fast mode is not available. Missing required commands.', 'wp-vault'),
        );
    }

    /**
     * Check if legacy mode (PHP ZipArchive) is available
     * 
     * @return array Array with availability status and details
     */
    public static function check_legacy_mode()
    {
        $zip_check = self::check_ziparchive();
        $php_version_ok = version_compare(PHP_VERSION, '5.2.0', '>=');

        $available = $zip_check['available'] && $php_version_ok;

        $requirements = array();
        $requirements[] = array(
            'name' => __('PHP ZipArchive extension', 'wp-vault'),
            'available' => $zip_check['available'],
            'reason' => $zip_check['reason'],
        );
        $requirements[] = array(
            'name' => __('PHP version >= 5.2.0', 'wp-vault'),
            'available' => $php_version_ok,
            'reason' => $php_version_ok
                ? sprintf(__('PHP version %s meets requirement', 'wp-vault'), PHP_VERSION)
                : sprintf(__('PHP version %s does not meet requirement (>= 5.2.0)', 'wp-vault'), PHP_VERSION),
        );

        return array(
            'available' => $available,
            'requirements' => $requirements,
            'reason' => $available
                ? __('Legacy mode is available. PHP ZipArchive extension is installed.', 'wp-vault')
                : __('Legacy mode is not available. Missing required PHP extension or PHP version too old.', 'wp-vault'),
        );
    }

    /**
     * Get availability status for all compression modes
     * 
     * @return array Array with fast and legacy mode availability
     */
    public static function get_all_availability()
    {
        return array(
            'fast' => self::check_fast_mode(),
            'legacy' => self::check_legacy_mode(),
        );
    }
}

