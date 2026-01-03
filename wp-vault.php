<?php
/**
 * Plugin Name: WPVault Backup & Optimization
 * Plugin URI: https://github.com/wpvault-cloud/wp-vault-plugin
 * Description: Ultimate WordPress backup and optimization platform with multi-storage support
 * Version: 1.0.0
 * Author: WP Vault
 * Author URI: https://wpvault.cloud
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wp-vault
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('WP_VAULT_VERSION', '1.0.0');
define('WP_VAULT_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WP_VAULT_PLUGIN_URL', plugin_dir_url(__FILE__));
define('WP_VAULT_PLUGIN_FILE', __FILE__);

// Incremental backup constants
define('WPV_TIME_BUDGET', 15);              // seconds per run
define('WPV_MAX_BYTES_PER_RUN', 200 * 1024 * 1024);  // 200MB hashing budget
define('WPV_FINGERPRINT_BYTES', 64 * 1024); // 64KB
define('WPV_FULL_HASH_LIMIT', 50 * 1024 * 1024);     // 50MB max file size for full hash
define('WPV_UPLOAD_CHUNK_SIZE', 200 * 1024 * 1024);  // 200MB
define('WPV_MAX_FILES_PER_RUN', 200);

// Autoloader
spl_autoload_register(function ($class) {
    $prefix = 'WP_Vault\\';
    $base_dir = WP_VAULT_PLUGIN_DIR . 'includes/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relative_class = substr($class, $len);
    $file = $base_dir . 'class-' . str_replace(array('\\', '_'), '-', strtolower($relative_class)) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});

// Include core files
require_once WP_VAULT_PLUGIN_DIR . 'includes/class-wp-vault.php';
require_once WP_VAULT_PLUGIN_DIR . 'includes/storage/interface-storage-adapter.php';
require_once WP_VAULT_PLUGIN_DIR . 'includes/storage/class-storage-factory.php';

/**
 * Main plugin initialization
 */
function wp_vault_init()
{
    $plugin = new WP_Vault\WP_Vault();
    $plugin->run();
}

// Initialize plugin
add_action('plugins_loaded', 'wp_vault_init');

/**
 * Activation hook
 */
register_activation_hook(__FILE__, function () {
    require_once WP_VAULT_PLUGIN_DIR . 'includes/class-wp-vault-activator.php';
    WP_Vault\WP_Vault_Activator::activate();
});

/**
 * Deactivation hook
 */
register_deactivation_hook(__FILE__, function () {
    require_once WP_VAULT_PLUGIN_DIR . 'includes/class-wp-vault-deactivator.php';
    WP_Vault\WP_Vault_Deactivator::deactivate();
});
