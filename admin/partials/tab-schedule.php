<?php
/**
 * Schedule Tab Content
 * 
 * Backup scheduling configuration
 *
 * @package WP_Vault
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Display schedule tab content
 */
function wpvault_display_schedule_tab()
{
    require_once WP_VAULT_PLUGIN_DIR . 'includes/class-wp-vault-api.php';

    $api = new \WP_Vault\WP_Vault_API();
    $registered = (bool) get_option('wpv_site_id');
    $api_endpoint = get_option('wpv_api_endpoint', 'http://host.docker.internal:3000');

    // Fetch schedules from SaaS (if available)
    $schedules = array();
    if ($registered) {
        // TODO: Add API method to fetch schedules
        // For now, show placeholder
    }
    ?>

    <div class="wpv-tab-content" id="wpv-tab-schedule">
        <div class="wpv-section">
            <h2><?php esc_html_e('Backup Schedule', 'wp-vault'); ?></h2>

            <?php if (!$registered): ?>
                <div class="wpv-notice wpv-notice-warning">
                    <p><?php esc_html_e('Please register your site in Settings to use scheduled backups.', 'wp-vault'); ?></p>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=wp-vault&tab=settings')); ?>"
                        class="button button-primary">
                        <?php esc_html_e('Go to Settings', 'wp-vault'); ?>
                    </a>
                </div>
            <?php else: ?>
                <p class="wpv-description">
                    <?php esc_html_e('Configure automatic backups to run on a schedule. Schedules are managed in the WP Vault SaaS dashboard.', 'wp-vault'); ?>
                </p>

                <div class="wpv-schedule-info">
                    <div class="wpv-info-card">
                        <h3><?php esc_html_e('Schedule Status', 'wp-vault'); ?></h3>
                        <p class="wpv-status-text">
                            <span
                                class="wpv-status-badge wpv-status-disabled"><?php esc_html_e('Disabled', 'wp-vault'); ?></span>
                        </p>
                        <p class="wpv-info-text">
                            <?php esc_html_e('No active schedules. Create a schedule in the SaaS dashboard.', 'wp-vault'); ?>
                        </p>
                    </div>

                    <div class="wpv-info-card">
                        <h3><?php esc_html_e('Server Time', 'wp-vault'); ?></h3>
                        <p class="wpv-time-text"><?php echo esc_html(current_time('F-d-Y H:i')); ?></p>
                    </div>

                    <div class="wpv-info-card">
                        <h3><?php esc_html_e('Last Backup', 'wp-vault'); ?></h3>
                        <p class="wpv-info-text"><?php esc_html_e('The last backup message not found.', 'wp-vault'); ?></p>
                    </div>

                    <div class="wpv-info-card">
                        <h3><?php esc_html_e('Next Backup', 'wp-vault'); ?></h3>
                        <p class="wpv-info-text"><?php esc_html_e('N/A', 'wp-vault'); ?></p>
                    </div>
                </div>

                <div class="wpv-action-section">
                    <a href="<?php echo esc_url($api_endpoint); ?>/dashboard/schedules" target="_blank"
                        class="button button-primary">
                        <?php esc_html_e('Manage Schedules in SaaS Dashboard', 'wp-vault'); ?>
                        <span class="dashicons dashicons-external"
                            style="font-size: 14px; vertical-align: middle; margin-left: 5px;"></span>
                    </a>
                </div>

                <div class="wpv-help-section">
                    <h3><?php esc_html_e('How Scheduling Works', 'wp-vault'); ?></h3>
                    <ul class="wpv-help-list">
                        <li><?php esc_html_e('Schedules are created and managed in the WP Vault SaaS dashboard', 'wp-vault'); ?>
                        </li>
                        <li><?php esc_html_e('The SaaS dashboard will push backup jobs to your WordPress site', 'wp-vault'); ?>
                        </li>
                        <li><?php esc_html_e('If push fails, your site will poll the SaaS for pending jobs every 10 minutes', 'wp-vault'); ?>
                        </li>
                        <li><?php esc_html_e('This hybrid model ensures backups run even if your site is behind a firewall', 'wp-vault'); ?>
                        </li>
                    </ul>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php
}

// Call the function to display the tab
wpvault_display_schedule_tab();
?>