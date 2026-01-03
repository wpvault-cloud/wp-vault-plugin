<?php
/**
 * Connection Status and Storage Usage Widget
 * 
 * Displays SaaS connection status and storage usage information
 *
 * @package WP_Vault
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Display connection status widget
 */
function wpvault_display_connection_status()
{
    require_once WP_VAULT_PLUGIN_DIR . 'includes/class-wp-vault-api.php';

    $api = new \WP_Vault\WP_Vault_API();
    $registered = (bool) get_option('wpv_site_id');
    $site_id = get_option('wpv_site_id');
    $api_endpoint = get_option('wpv_api_endpoint', 'https://wpvault.cloud');
    $last_heartbeat = get_option('wpv_last_heartbeat_at');

    // Get cached connection status
    $connection_status = get_option('wpv_connection_status', 'unknown');
    $last_connection_check = get_option('wpv_last_connection_check_at');

    // If no cached status, check on page load (but don't force)
    if ($connection_status === 'unknown' && $registered) {
        $check_result = $api->check_connection(false); // Don't force, use cache if available
        $connection_status = isset($check_result['status']) ? $check_result['status'] : 'unknown';
    }

    $is_connected = $connection_status === 'connected';

    // Get storage config to show usage
    $storage_data = null;
    $storage_usage = null;
    if ($registered) {
        $storage_result = $api->get_storage_config();
        if ($storage_result['success'] && isset($storage_result['storages'])) {
            $storage_data = $storage_result;
            // Find WP Vault Cloud storage
            foreach ($storage_result['storages'] as $storage) {
                if ($storage['type'] === 'wp_vault_cloud') {
                    $storage_usage = $storage;
                    break;
                }
            }
        }
    }
    ?>

    <div class="wpv-card wpv-connection-status">
        <div class="wpv-card-header">
            <h3><?php esc_html_e('Vault Cloud Connection', 'wp-vault'); ?></h3>
        </div>
        <div class="wpv-card-content">
            <?php if ($registered): ?>
                <div class="wpv-status-indicator <?php echo $is_connected ? 'wpv-status-connected' : 'wpv-status-disconnected'; ?>"
                    id="wpv-connection-status-indicator">
                    <span class="wpv-status-dot"></span>
                    <span
                        id="wpv-connection-status-text"><?php echo $is_connected ? esc_html__('Connected', 'wp-vault') : esc_html__('Disconnected', 'wp-vault'); ?></span>
                    <button type="button" id="wpv-recheck-connection" class="button button-small"
                        style="margin-left: 10px; padding: 2px 8px; font-size: 11px; height: auto; line-height: 1.5;"
                        title="<?php esc_attr_e('Recheck connection', 'wp-vault'); ?>">
                        <span class="dashicons dashicons-update"
                            style="font-size: 14px; width: 14px; height: 14px; line-height: 1.2;"></span>
                    </button>
                </div>

                <div class="wpv-info-row">
                    <span class="wpv-info-label"><?php esc_html_e('Site ID:', 'wp-vault'); ?></span>
                    <span class="wpv-info-value wpv-code"><?php echo esc_html(substr($site_id, 0, 12)); ?>...</span>
                </div>

                <?php if ($last_heartbeat): ?>
                    <div class="wpv-info-row">
                        <span class="wpv-info-label"><?php esc_html_e('Last Sync:', 'wp-vault'); ?></span>
                        <span class="wpv-info-value"
                            id="wpv-last-sync"><?php echo esc_html(human_time_diff(strtotime($last_heartbeat), current_time('timestamp'))) . ' ago'; ?></span>
                    </div>
                <?php endif; ?>

                <?php if ($last_connection_check): ?>
                    <div class="wpv-info-row">
                        <span class="wpv-info-label"><?php esc_html_e('Last Check:', 'wp-vault'); ?></span>
                        <span class="wpv-info-value"
                            id="wpv-last-check"><?php echo esc_html(human_time_diff(strtotime($last_connection_check), current_time('timestamp'))) . ' ago'; ?></span>
                    </div>
                <?php endif; ?>

                <?php if ($api_endpoint): ?>
                    <div class="wpv-info-row">
                        <span class="wpv-info-label"><?php esc_html_e('Cloud URL:', 'wp-vault'); ?></span>
                        <span class="wpv-info-value wpv-code-small"><?php
                        $parsed = wp_parse_url($api_endpoint);
                        echo esc_html(isset($parsed['host']) ? $parsed['host'] : '');
                        ?></span>
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <div class="wpv-status-indicator wpv-status-disconnected">
                    <span class="wpv-status-dot"></span>
                    <span><?php esc_html_e('Not Connected', 'wp-vault'); ?></span>
                </div>
                <p class="wpv-info-text">
                    <?php esc_html_e('Please register your site in Settings to connect to Vault Cloud.', 'wp-vault'); ?>
                </p>
                <a href="<?php echo esc_url(admin_url('admin.php?page=wp-vault&tab=settings')); ?>"
                    class="button button-small"><?php esc_html_e('Go to Settings', 'wp-vault'); ?></a>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($registered && $storage_usage): ?>
        <div class="wpv-card wpv-storage-usage">
            <div class="wpv-card-header">
                <h3><?php esc_html_e('Storage Usage', 'wp-vault'); ?></h3>
            </div>
            <div class="wpv-card-content">
                <?php
                $used_bytes = isset($storage_usage['used_bytes']) ? $storage_usage['used_bytes'] : 0;
                $quota_bytes = isset($storage_usage['quota_bytes']) ? $storage_usage['quota_bytes'] : 0;
                $free_bytes = isset($storage_usage['free_bytes']) ? $storage_usage['free_bytes'] : 0;
                $usage_percent = $quota_bytes > 0 ? round(($used_bytes / $quota_bytes) * 100) : 0;
                $plan = isset($storage_usage['plan']) ? $storage_usage['plan'] : 'free';
                ?>

                <div class="wpv-storage-plan">
                    <span
                        class="wpv-plan-badge wpv-plan-<?php echo esc_attr($plan); ?>"><?php echo esc_html(ucfirst($plan)); ?></span>
                </div>

                <div class="wpv-storage-stats">
                    <div class="wpv-storage-stat">
                        <div class="wpv-stat-value"><?php echo esc_html(size_format($used_bytes, 2)); ?></div>
                        <div class="wpv-stat-label"><?php esc_html_e('Used', 'wp-vault'); ?></div>
                    </div>
                    <div class="wpv-storage-stat">
                        <div class="wpv-stat-value"><?php echo esc_html(size_format($free_bytes, 2)); ?></div>
                        <div class="wpv-stat-label"><?php esc_html_e('Available', 'wp-vault'); ?></div>
                    </div>
                    <div class="wpv-storage-stat">
                        <div class="wpv-stat-value"><?php echo esc_html(size_format($quota_bytes, 2)); ?></div>
                        <div class="wpv-stat-label"><?php esc_html_e('Total', 'wp-vault'); ?></div>
                    </div>
                </div>

                <div class="wpv-progress-bar">
                    <div class="wpv-progress-fill" style="width: <?php echo esc_attr($usage_percent); ?>%;"></div>
                </div>

                <div class="wpv-usage-percent">
                    <?php echo esc_html($usage_percent); ?>% <?php esc_html_e('used', 'wp-vault'); ?>
                </div>
            </div>
        </div>
    <?php endif;
}

// Call the function to display the widget
wpvault_display_connection_status();
?>