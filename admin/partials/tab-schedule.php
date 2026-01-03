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
    require_once WP_VAULT_PLUGIN_DIR . 'includes/class-wp-vault-scheduler.php';

    $api = new \WP_Vault\WP_Vault_API();
    $registered = (bool) get_option('wpv_site_id');
    $api_endpoint = get_option('wpv_api_endpoint', 'https://api.wpvault.cloud');

    // Get local schedule settings
    $scheduler = new \WP_Vault\WP_Vault_Scheduler();
    $local_schedule = $scheduler->get_schedule();
    $next_run = $scheduler->get_formatted_next_run();
    ?>

    <div class="wpv-tab-content" id="wpv-tab-schedule">
        <div class="wpv-section">
            <h2><?php esc_html_e('Backup Schedule', 'wp-vault'); ?></h2>

            <?php if (!$registered): ?>
                <!-- Not Connected to Cloud Banner -->
                <div class="wpv-notice wpv-notice-info">
                    <p>
                        <strong><?php esc_html_e('Not connected to Vault Cloud', 'wp-vault'); ?></strong><br>
                        <?php esc_html_e('You are running in Local Mode. Backups will be stored locally on your server.', 'wp-vault'); ?>
                    </p>
                    <p class="description" style="margin-top: 5px;">
                        <?php esc_html_e('Connect to Vault Cloud for remote management, secure cloud storage offloading, and reliability monitoring.', 'wp-vault'); ?>
                    </p>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=wp-vault&tab=settings')); ?>"
                        class="button button-secondary" style="margin-top: 10px;">
                        <?php esc_html_e('Connect to Vault Cloud', 'wp-vault'); ?>
                    </a>
                </div>
            <?php else: ?>
                <!-- Connected to Cloud Banner -->
                <div class="wpv-notice wpv-notice-success">
                    <p>
                        <strong><?php esc_html_e('Connected to Vault Cloud', 'wp-vault'); ?></strong><br>
                        <?php esc_html_e('Your site is connected. Scehdules can be managed from the dashboard or locally overridden here.', 'wp-vault'); ?>
                    </p>
                </div>
            <?php endif; ?>

            <p class="wpv-description">
                <?php esc_html_e('Configure automatic backups to run on a schedule.', 'wp-vault'); ?>
            </p>

            <div class="wpv-schedule-info">
                <div class="wpv-info-card">
                    <h3><?php esc_html_e('Schedule Status', 'wp-vault'); ?></h3>
                    <p class="wpv-status-text">
                        <?php if ($local_schedule['enabled']): ?>
                            <span class="wpv-status-badge wpv-status-success"><?php esc_html_e('Active', 'wp-vault'); ?></span>
                        <?php else: ?>
                            <span
                                class="wpv-status-badge wpv-status-disabled"><?php esc_html_e('Disabled', 'wp-vault'); ?></span>
                        <?php endif; ?>
                    </p>
                    <p class="wpv-info-text">
                        <?php
                        if ($local_schedule['enabled']) {
                            printf(
                                /* translators: %s: Frequency */
                                esc_html__('Running %s', 'wp-vault'),
                                esc_html(ucfirst($local_schedule['frequency']))
                            );
                        } else {
                            esc_html_e('No active local schedule.', 'wp-vault');
                        }
                        ?>
                    </p>
                </div>

                <div class="wpv-info-card">
                    <h3><?php esc_html_e('Server Time', 'wp-vault'); ?></h3>
                    <p class="wpv-time-text"><?php echo esc_html(current_time('F j, Y H:i')); ?></p>
                </div>

                <div class="wpv-info-card">
                    <h3><?php esc_html_e('Next Run', 'wp-vault'); ?></h3>
                    <p class="wpv-info-text"><?php echo esc_html($next_run); ?></p>
                </div>
            </div>

            <!-- Local Schedule Form -->
            <div class="wpv-section"
                style="margin-top: 20px; padding: 20px; background: #fff; border: 1px solid #ccd0d4; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
                <h3><?php esc_html_e('Configure Schedule', 'wp-vault'); ?></h3>
                <form id="wpv-schedule-form">
                    <table class="form-table">
                        <tr>
                            <th scope="row"><?php esc_html_e('Enable Schedule', 'wp-vault'); ?></th>
                            <td>
                                <label class="switch">
                                    <input type="checkbox" name="enabled" id="schedule-enabled" value="1" <?php checked($local_schedule['enabled']); ?>>
                                    <span class="slider round"></span>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Frequency', 'wp-vault'); ?></th>
                            <td>
                                <select name="frequency" id="schedule-frequency" class="regular-text">
                                    <option value="hourly" <?php selected($local_schedule['frequency'], 'hourly'); ?>>
                                        <?php esc_html_e('Every Hour', 'wp-vault'); ?></option>
                                    <option value="twicedaily" <?php selected($local_schedule['frequency'], 'twicedaily'); ?>><?php esc_html_e('Twice Daily (Every 12 Hours)', 'wp-vault'); ?></option>
                                    <option value="daily" <?php selected($local_schedule['frequency'], 'daily'); ?>>
                                        <?php esc_html_e('Daily', 'wp-vault'); ?></option>
                                    <option value="wpv_weekly" <?php selected($local_schedule['frequency'], 'wpv_weekly'); ?>><?php esc_html_e('Weekly', 'wp-vault'); ?></option>
                                    <option value="wpv_monthly" <?php selected($local_schedule['frequency'], 'wpv_monthly'); ?>><?php esc_html_e('Monthly', 'wp-vault'); ?></option>
                                </select>
                                <p class="description">
                                    <?php esc_html_e('Backups trigger using WordPress Cron.', 'wp-vault'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Backup Type', 'wp-vault'); ?></th>
                            <td>
                                <fieldset>
                                    <label>
                                        <input type="radio" name="backup_type" value="full" <?php checked($local_schedule['backup_type'], 'full'); ?>>
                                        <?php esc_html_e('Full (Database + Files)', 'wp-vault'); ?>
                                    </label><br>
                                    <label>
                                        <input type="radio" name="backup_type" value="db" <?php checked($local_schedule['backup_type'], 'db'); ?>>
                                        <?php esc_html_e('Database Only', 'wp-vault'); ?>
                                    </label><br>
                                    <label>
                                        <input type="radio" name="backup_type" value="files" <?php checked($local_schedule['backup_type'], 'files'); ?>>
                                        <?php esc_html_e('Files Only', 'wp-vault'); ?>
                                    </label>
                                </fieldset>
                            </td>
                        </tr>
                    </table>

                    <p class="submit">
                        <button type="button" id="save-schedule" class="button button-primary"
                            style="min-width: 150px; text-align: center;">
                            <?php esc_html_e('Save Changes', 'wp-vault'); ?>
                        </button>
                        <span id="save-schedule-result" style="margin-left: 10px;"></span>
                    </p>
                </form>
            </div>

            <?php if ($registered): ?>
                <div class="wpv-action-section" style="margin-top: 20px;">
                    <a href="<?php echo esc_url($api_endpoint); ?>/dashboard/schedules" target="_blank"
                        class="button button-secondary">
                        <?php esc_html_e('View Cloud Schedules', 'wp-vault'); ?>
                        <span class="dashicons dashicons-external"
                            style="font-size: 14px; vertical-align: middle; margin-left: 5px;"></span>
                    </a>
                </div>
            <?php endif; ?>

            <div class="wpv-help-section">
                <h3><?php esc_html_e('How Scheduling Works', 'wp-vault'); ?></h3>
                <ul class="wpv-help-list">
                    <li><?php esc_html_e('Schedules run using WP Cron, which requires site traffic or a system cron task to trigger.', 'wp-vault'); ?>
                    </li>
                    <li><?php esc_html_e('Local schedules run independently of Vault Cloud.', 'wp-vault'); ?></li>
                    <li><?php esc_html_e('If connected to Vault Cloud, you can also manage schedules remotely for centralized control.', 'wp-vault'); ?>
                    </li>
                </ul>
            </div>
        </div>
    </div>

    <script>
        jQuery(document).ready(function ($) {
            $('#save-schedule').on('click', function () {
                var $btn = $(this);
                var $result = $('#save-schedule-result');

                var data = {
                    action: 'wpv_save_schedule',
                    nonce: wpVault.nonce,
                    enabled: $('#schedule-enabled').is(':checked'),
                    frequency: $('#schedule-frequency').val(),
                    backup_type: $('input[name="backup_type"]:checked').val()
                };

                $btn.prop('disabled', true).text('<?php echo esc_js(__('Saving...', 'wp-vault')); ?>');
                $result.html('');

                $.post(ajaxurl, data, function (response) {
                    if (response.success) {
                        $result.html('<span style="color: green;">✓ ' + response.data.message + '</span>');
                        // Update next run display if provided
                        if (response.data.next_run) {
                            $('.wpv-info-card:last .wpv-info-text').text(response.data.next_run);
                        }
                        setTimeout(function () {
                            location.reload();
                        }, 1500);
                    } else {
                        $result.html('<span style="color: red;">✗ ' + (response.data.message || 'Error saving settings') + '</span>');
                        $btn.prop('disabled', false).text('<?php echo esc_js(__('Save Changes', 'wp-vault')); ?>');
                    }
                }).fail(function () {
                    $result.html('<span style="color: red;">✗ Server error</span>');
                    $btn.prop('disabled', false).text('<?php echo esc_js(__('Save Changes', 'wp-vault')); ?>');
                });
            });
        });
    </script>

    <style>
        /* Toggle Switch Styles */
        .switch {
            position: relative;
            display: inline-block;
            width: 50px;
            height: 24px;
        }

        .switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }

        .slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #ccc;
            -webkit-transition: .4s;
            transition: .4s;
        }

        .slider:before {
            position: absolute;
            content: "";
            height: 16px;
            width: 16px;
            left: 4px;
            bottom: 4px;
            background-color: white;
            -webkit-transition: .4s;
            transition: .4s;
        }

        input:checked+.slider {
            background-color: #2271b1;
        }

        input:focus+.slider {
            box-shadow: 0 0 1px #2271b1;
        }

        input:checked+.slider:before {
            -webkit-transform: translateX(26px);
            -ms-transform: translateX(26px);
            transform: translateX(26px);
        }

        .slider.round {
            border-radius: 34px;
        }

        .slider.round:before {
            border-radius: 50%;
        }
    </style>
    <?php
}

// Call the function to display the tab
wpvault_display_schedule_tab();
?>