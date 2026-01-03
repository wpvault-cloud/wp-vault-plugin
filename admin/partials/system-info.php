<?php
/**
 * System Information Cards
 * 
 * Displays system information in vertical cards (right sidebar)
 *
 * @package WP_Vault
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Display system information sidebar
 */
function wpvault_display_system_info()
{
    global $wpdb;

    // Get system information
    $plugin_version = WP_VAULT_VERSION;
    $wp_version = get_bloginfo('version');
    $php_version = PHP_VERSION;
    $db_version = $wpdb->db_version();
    $db_name = $wpdb->dbname;
    $memory_limit = ini_get('memory_limit');
    $max_upload = size_format(wp_max_upload_size());
    $host_class = get_option('wpv_host_class', 'unknown');
    $host_class_label = ucfirst($host_class);

    // Detect server software
    $server_software = 'Unknown';
    if (isset($_SERVER['SERVER_SOFTWARE'])) {
        $server_software = $_SERVER['SERVER_SOFTWARE'];
        if (stripos($server_software, 'nginx') !== false) {
            $server_software = 'Nginx';
        } elseif (stripos($server_software, 'apache') !== false) {
            $server_software = 'Apache';
        } elseif (stripos($server_software, 'litespeed') !== false) {
            $server_software = 'LiteSpeed';
        }
    }

    // Detect database type
    $db_type = 'MySQL';
    if (stripos($db_version, 'mariadb') !== false) {
        $db_type = 'MariaDB';
    }
    ?>

    <div class="wpv-sidebar">
        <!-- System Information Card -->
        <div class="wpv-card">
            <div class="wpv-card-header">
                <h3><?php esc_html_e('System Information', 'wp-vault'); ?></h3>
            </div>
            <div class="wpv-card-content">
                <div class="wpv-info-row">
                    <span class="wpv-info-label"><?php esc_html_e('Plugin Version:', 'wp-vault'); ?></span>
                    <span class="wpv-info-value"><?php echo esc_html($plugin_version); ?></span>
                </div>
                <div class="wpv-info-row">
                    <span class="wpv-info-label"><?php esc_html_e('WordPress:', 'wp-vault'); ?></span>
                    <span class="wpv-info-value"><?php echo esc_html($wp_version); ?></span>
                </div>
                <div class="wpv-info-row">
                    <span class="wpv-info-label"><?php esc_html_e('PHP Version:', 'wp-vault'); ?></span>
                    <span class="wpv-info-value"><?php echo esc_html($php_version); ?></span>
                </div>
                <div class="wpv-info-row">
                    <span class="wpv-info-label"><?php esc_html_e('Database:', 'wp-vault'); ?></span>
                    <span class="wpv-info-value"><?php echo esc_html($db_type . ' ' . $db_version); ?></span>
                </div>
                <div class="wpv-info-row">
                    <span class="wpv-info-label"><?php esc_html_e('Server:', 'wp-vault'); ?></span>
                    <span class="wpv-info-value"><?php echo esc_html($server_software); ?></span>
                </div>
                <div class="wpv-info-row">
                    <span class="wpv-info-label"><?php esc_html_e('Memory Limit:', 'wp-vault'); ?></span>
                    <span class="wpv-info-value"><?php echo esc_html($memory_limit); ?></span>
                </div>
                <div class="wpv-info-row">
                    <span class="wpv-info-label"><?php esc_html_e('Max Upload:', 'wp-vault'); ?></span>
                    <span class="wpv-info-value"><?php echo esc_html($max_upload); ?></span>
                </div>
                <div class="wpv-info-row">
                    <span class="wpv-info-label"><?php esc_html_e('Host Class:', 'wp-vault'); ?></span>
                    <span class="wpv-info-value"><?php echo esc_html($host_class_label); ?></span>
                </div>
            </div>
        </div>

        <!-- Quick Links Card -->
        <div class="wpv-card">
            <div class="wpv-card-header">
                <h3><?php esc_html_e('Quick Links', 'wp-vault'); ?></h3>
            </div>
            <div class="wpv-card-content">
                <ul class="wpv-quick-links">
                    <li><a
                            href="<?php echo esc_url(admin_url('admin.php?page=wp-vault&tab=dashboard')); ?>"><?php esc_html_e('Dashboard', 'wp-vault'); ?></a>
                    </li>
                    <li><a
                            href="<?php echo esc_url(admin_url('admin.php?page=wp-vault&tab=backups')); ?>"><?php esc_html_e('View All Backups', 'wp-vault'); ?></a>
                    </li>
                    <li><a
                            href="<?php echo esc_url(admin_url('admin.php?page=wp-vault&tab=restores')); ?>"><?php esc_html_e('Restores', 'wp-vault'); ?></a>
                    </li>
                    <li><a
                            href="<?php echo esc_url(admin_url('admin.php?page=wp-vault&tab=schedule')); ?>"><?php esc_html_e('Backup Schedule', 'wp-vault'); ?></a>
                    </li>
                    <li><a
                            href="<?php echo esc_url(admin_url('admin.php?page=wp-vault&tab=settings')); ?>"><?php esc_html_e('Settings', 'wp-vault'); ?></a>
                    </li>
                    <li><a
                            href="<?php echo esc_url(admin_url('admin.php?page=wp-vault&tab=storage')); ?>"><?php esc_html_e('Storage', 'wp-vault'); ?></a>
                    </li>
                    <?php
                    $api_endpoint = get_option('wpv_api_endpoint', 'http://host.docker.internal:3000');
                    if ($api_endpoint):
                        ?>
                        <li><a href="<?php echo esc_url($api_endpoint); ?>/dashboard"
                                target="_blank"><?php esc_html_e('Vault Cloud Dashboard', 'wp-vault'); ?> <span
                                    class="dashicons dashicons-external"
                                    style="font-size: 14px; vertical-align: middle;"></span></a></li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>

        <!-- Help & Support Card -->
        <div class="wpv-card">
            <div class="wpv-card-header">
                <h3><?php esc_html_e('Help & Support', 'wp-vault'); ?></h3>
            </div>
            <div class="wpv-card-content">
                <ul class="wpv-quick-links">
                    <li><a href="https://wpvault.cloud/docs"
                            target="_blank"><?php esc_html_e('Documentation', 'wp-vault'); ?> <span
                                class="dashicons dashicons-external"
                                style="font-size: 14px; vertical-align: middle;"></span></a></li>
                    <li><a href="https://wpvault.cloud/support"
                            target="_blank"><?php esc_html_e('Get Support', 'wp-vault'); ?>
                            <span class="dashicons dashicons-external"
                                style="font-size: 14px; vertical-align: middle;"></span></a></li>
                    <li><a href="https://wpvault.cloud/changelog"
                            target="_blank"><?php esc_html_e('Changelog', 'wp-vault'); ?>
                            <span class="dashicons dashicons-external"
                                style="font-size: 14px; vertical-align: middle;"></span></a></li>
                </ul>
            </div>
        </div>
    </div>
    <?php
}

// Call the function to display the sidebar
wpvault_display_system_info();
?>