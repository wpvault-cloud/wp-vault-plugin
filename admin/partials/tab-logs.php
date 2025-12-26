<?php
/**
 * Logs Tab Content
 * 
 * Backup and restore logs viewer
 *
 * @package WP_Vault
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once WP_VAULT_PLUGIN_DIR . 'includes/class-wp-vault-api.php';

$api = new \WP_Vault\WP_Vault_API();
$registered = (bool) get_option('wpv_site_id');

// Get recent logs from local database
global $wpdb;
$logs_table = $wpdb->prefix . 'wp_vault_job_logs';
$recent_logs = array();

if ($wpdb->get_var("SHOW TABLES LIKE '$logs_table'") == $logs_table) {
    $recent_logs = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT * FROM $logs_table ORDER BY created_at DESC LIMIT %d",
            100
        ),
        ARRAY_A
    );
}
?>

<div class="wpv-tab-content" id="wpv-tab-logs">
    <div class="wpv-section">
        <h2><?php _e('Backup & Restore Logs', 'wp-vault'); ?></h2>

        <div class="wpv-logs-controls">
            <select id="wpv-log-filter" class="wpv-select">
                <option value="all"><?php _e('All Logs', 'wp-vault'); ?></option>
                <option value="backup"><?php _e('Backup Logs', 'wp-vault'); ?></option>
                <option value="restore"><?php _e('Restore Logs', 'wp-vault'); ?></option>
            </select>

            <select id="wpv-log-severity" class="wpv-select">
                <option value="all"><?php _e('All Severities', 'wp-vault'); ?></option>
                <option value="ERROR"><?php _e('Errors Only', 'wp-vault'); ?></option>
                <option value="WARNING"><?php _e('Warnings & Errors', 'wp-vault'); ?></option>
            </select>

            <button id="wpv-refresh-logs" class="button">
                <span class="dashicons dashicons-update"></span>
                <?php _e('Refresh', 'wp-vault'); ?>
            </button>
        </div>

        <?php if (empty($recent_logs)): ?>
            <div class="wpv-empty-state">
                <p><?php _e('No logs found. Logs will appear here after you run a backup or restore.', 'wp-vault'); ?></p>
            </div>
        <?php else: ?>
            <div class="wpv-logs-container">
                <table class="wp-list-table widefat fixed striped" id="wpv-logs-table">
                    <thead>
                        <tr>
                            <th style="width:150px;"><?php _e('Time', 'wp-vault'); ?></th>
                            <th style="width:100px;"><?php _e('Severity', 'wp-vault'); ?></th>
                            <th style="width:150px;"><?php _e('Step', 'wp-vault'); ?></th>
                            <th><?php _e('Message', 'wp-vault'); ?></th>
                            <th style="width:100px;"><?php _e('Actions', 'wp-vault'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent_logs as $log): ?>
                            <tr class="wpv-log-row wpv-log-<?php echo esc_attr(strtolower($log['severity'])); ?>"
                                data-severity="<?php echo esc_attr($log['severity']); ?>"
                                data-job-id="<?php echo esc_attr($log['job_id']); ?>">
                                <td><?php echo esc_html(date('M j, Y H:i:s', strtotime($log['created_at']))); ?></td>
                                <td>
                                    <span
                                        class="wpv-log-severity wpv-severity-<?php echo esc_attr(strtolower($log['severity'])); ?>">
                                        <?php echo esc_html($log['severity']); ?>
                                    </span>
                                </td>
                                <td><?php echo esc_html($log['step'] ?: '—'); ?></td>
                                <td><?php echo esc_html($log['message']); ?></td>
                                <td>
                                    <?php if ($log['job_id']): ?>
                                        <button class="button button-small wpv-view-job-logs"
                                            data-job-id="<?php echo esc_attr($log['job_id']); ?>">
                                            <?php _e('View All', 'wp-vault'); ?>
                                        </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>