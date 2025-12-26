<?php
/**
 * Restores Tab Content
 * 
 * Restore history/logs only (no quick actions)
 *
 * @package WP_Vault
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Display restores tab content
 */
function wpvault_display_restores_tab()
{
    // Get restore jobs from database
    global $wpdb;
    $table = $wpdb->prefix . 'wp_vault_jobs';
    $restores = array();

    $table_escaped = esc_sql($table);
    if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) == $table) {
        $table_escaped = esc_sql($table);
        $restores = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table_escaped} WHERE job_type = %s ORDER BY started_at DESC, created_at DESC LIMIT 50",
                'restore'
            )
        );
    }
    ?>

    <div class="wpv-tab-content" id="wpv-tab-restores">
        <?php if (empty($restores)): ?>
            <div class="wpv-empty-state">
                <p><?php esc_html_e('No restore history yet. Restores will appear here after you restore a backup.', 'wp-vault'); ?>
                </p>
            </div>
        <?php else: ?>
            <p class="wpv-backup-summary">
                <?php
                /* translators: %d: number of restores */
                printf(esc_html__('Found %d restore(s) in history', 'wp-vault'), count($restores));
                ?>
            </p>

            <table class="wp-list-table widefat fixed striped" id="wpv-restores-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Restore ID', 'wp-vault'); ?></th>
                        <th><?php esc_html_e('Status', 'wp-vault'); ?></th>
                        <th><?php esc_html_e('Progress', 'wp-vault'); ?></th>
                        <th><?php esc_html_e('Date', 'wp-vault'); ?></th>
                        <th><?php esc_html_e('Actions', 'wp-vault'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($restores as $restore): ?>
                        <tr class="wpv-restore-row" data-restore-id="<?php echo esc_attr($restore->backup_id); ?>">
                            <td>
                                <code><?php echo esc_html(substr($restore->backup_id, 0, 20)); ?></code>
                            </td>
                            <td>
                                <span class="wpv-status wpv-status-<?php echo esc_attr($restore->status); ?>">
                                    <?php echo esc_html(ucfirst($restore->status)); ?>
                                </span>
                            </td>
                            <td>
                                <?php
                                if ($restore->status === 'running' || $restore->status === 'completed') {
                                    echo esc_html($restore->progress_percent) . '%';
                                    if ($restore->status === 'running') {
                                        echo ' <span class="spinner is-active" style="float: none; margin: 0 0 0 5px;"></span>';
                                    }
                                } else {
                                    echo '—';
                                }
                                ?>
                            </td>
                            <td>
                                <?php
                                if ($restore->started_at) {
                                    echo esc_html(mysql2date('M j, Y g:i a', $restore->started_at));
                                } else {
                                    echo esc_html(mysql2date('M j, Y g:i a', $restore->created_at));
                                }
                                ?>
                            </td>
                            <td>
                                <button class="button button-small wpv-show-restore-logs"
                                    data-restore-id="<?php echo esc_attr($restore->backup_id); ?>">
                                    <?php esc_html_e('Show Logs', 'wp-vault'); ?>
                                </button>
                                <?php if ($restore->status === 'running'): ?>
                                    <button class="button button-small wpv-cancel-restore"
                                        data-restore-id="<?php echo esc_attr($restore->backup_id); ?>"
                                        style="margin-left: 5px; color: #dc3232;">
                                        <?php esc_html_e('Cancel', 'wp-vault'); ?>
                                    </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <script>
        jQuery(document).ready(function ($) {
            var currentLogFile = null;

            // Show restore logs
            $(document).on('click', '.wpv-show-restore-logs', function () {
                var restoreId = $(this).data('restore-id');
                $('#wpv-logs-modal').show();
                $('#wpv-logs-backup-id').text('Restore ID: ' + restoreId);
                $('#wpv-logs-content').html('<div style="color:#888;"><?php echo esc_js(esc_html__('Loading logs...', 'wp-vault')); ?></div>');
                $('#wpv-download-logs-btn').hide();
                currentLogFile = null;

                // Get restore status to find log file path
                $.post(ajaxurl, {
                    action: 'wpv_get_restore_status',
                    restore_id: restoreId,
                    nonce: wpVault.nonce
                }, function (response) {
                    if (response.success && response.data.log_file_path) {
                        currentLogFile = response.data.log_file_path;
                        $('#wpv-download-logs-btn').show();
                        loadLogs(currentLogFile);
                    } else {
                        // Try to read logs from response
                        if (response.success && response.data.logs && response.data.logs.length > 0) {
                            renderLogsInModal(response.data.logs);
                        } else {
                            $('#wpv-logs-content').html('<div style="color:#d63638;"><?php echo esc_js(esc_html__('No logs found for this restore.', 'wp-vault')); ?></div>');
                        }
                    }
                }).fail(function () {
                    $('#wpv-logs-content').html('<div style="color:#d63638;"><?php echo esc_js(esc_html__('Error loading logs.', 'wp-vault')); ?></div>');
                });
            });

            // Load logs from file
            function loadLogs(logFilePath) {
                $.post(ajaxurl, {
                    action: 'wpv_read_log',
                    log_file: logFilePath,
                    lines: -200, // Last 200 lines
                    nonce: wpVault.nonce
                }, function (response) {
                    if (response.success && response.data.content) {
                        var logLines = response.data.content.split('\n');
                        var html = '';
                        logLines.forEach(function (line) {
                            if (line.trim()) {
                                // Parse log line: [timestamp][level] message
                                var match = line.match(/^\[([^\]]+)\]\[([^\]]+)\]\s*(.+)$/);
                                if (match) {
                                    var timestamp = match[1];
                                    var level = match[2];
                                    var message = $('<div/>').text(match[3]).html();
                                    var levelColor = level === 'ERROR' ? '#f48771' : (level === 'WARNING' ? '#dcdcaa' : '#4ec9b0');
                                    html += '<div style="margin-bottom:2px;"><span style="color:#858585;">[' + timestamp + ']</span> <span style="color:' + levelColor + '; font-weight:bold;">[' + level + ']</span> <span style="color:#d4d4d4;">' + message + '</span></div>';
                                } else {
                                    // Plain text line
                                    html += '<div style="margin-bottom:2px; color:#d4d4d4;">' + $('<div/>').text(line).html() + '</div>';
                                }
                            }
                        });
                        $('#wpv-logs-content').html(html || '<div style="color:#888;"><?php echo esc_js(esc_html__('No log content found.', 'wp-vault')); ?></div>');
                        var $content = $('#wpv-logs-content');
                        $content.scrollTop($content[0].scrollHeight);
                    } else {
                        $('#wpv-logs-content').html('<div style="color:#d63638;"><?php echo esc_js(esc_html__('Error reading log file.', 'wp-vault')); ?></div>');
                    }
                }).fail(function () {
                    $('#wpv-logs-content').html('<div style="color:#d63638;"><?php echo esc_js(esc_html__('Error loading logs.', 'wp-vault')); ?></div>');
                });
            }

            // Render logs in modal (from status response)
            function renderLogsInModal(logs) {
                var html = '';
                logs.forEach(function (log) {
                    var time = log.created_at ? new Date(log.created_at).toLocaleTimeString() : '';
                    var severity = log.severity || 'INFO';
                    var levelColor = severity === 'ERROR' ? '#f48771' : (severity === 'WARNING' ? '#dcdcaa' : '#4ec9b0');
                    html += '<div style="margin-bottom:2px;"><span style="color:#858585;">[' + time + ']</span> <span style="color:' + levelColor + '; font-weight:bold;">[' + severity + ']</span> <span style="color:#d4d4d4;">' + $('<div/>').text(log.message).html() + '</span></div>';
                });
                $('#wpv-logs-content').html(html || '<div style="color:#888;"><?php echo esc_js(esc_html__('No logs available.', 'wp-vault')); ?></div>');
                var $content = $('#wpv-logs-content');
                $content.scrollTop($content[0].scrollHeight);
            }

            // Cancel restore
            $(document).on('click', '.wpv-cancel-restore', function () {
                if (!confirm('<?php echo esc_js(esc_html__('Are you sure you want to cancel this restore?', 'wp-vault')); ?>')) {
                    return;
                }

                var restoreId = $(this).data('restore-id');

                $.post(ajaxurl, {
                    action: 'wpv_cancel_restore',
                    restore_id: restoreId,
                    nonce: wpVault.nonce
                }, function (response) {
                    if (response.success) {
                        location.reload();
                    } else {
                        alert('<?php echo esc_js(esc_html__('Failed to cancel restore:', 'wp-vault')); ?> ' + (response.data.error || '<?php echo esc_js(esc_html__('Unknown error', 'wp-vault')); ?>'));
                    }
                });
            });

            // Auto-refresh running restores
            function refreshRunningRestores() {
                var runningRestores = $('.wpv-restore-row').filter(function () {
                    return $(this).find('.wpv-status-running').length > 0;
                });

                if (runningRestores.length > 0) {
                    runningRestores.each(function () {
                        var restoreId = $(this).data('restore-id');
                        $.post(ajaxurl, {
                            action: 'wpv_get_restore_status',
                            restore_id: restoreId,
                            nonce: wpVault.nonce
                        }, function (response) {
                            if (response.success) {
                                var status = response.data.status;
                                var progress = response.data.progress;

                                // Update status and progress
                                var $row = $('.wpv-restore-row[data-restore-id="' + restoreId + '"]');
                                $row.find('.wpv-status').removeClass('wpv-status-running wpv-status-completed wpv-status-failed wpv-status-cancelled')
                                    .addClass('wpv-status-' + status).text(status.charAt(0).toUpperCase() + status.slice(1));
                                $row.find('td:nth-child(3)').html(progress + '%' + (status === 'running' ? ' <span class="spinner is-active" style="float: none; margin: 0 0 0 5px;"></span>' : ''));

                                if (status === 'completed' || status === 'failed' || status === 'cancelled') {
                                    location.reload();
                                }
                            }
                        });
                    });
                }
            }

            // Poll every 3 seconds if there are running restores
            if ($('.wpv-restore-row .wpv-status-running').length > 0) {
                setInterval(refreshRunningRestores, 3000);
            }
        });
    </script>
    <?php
}

// Call the function to display the tab
wpvault_display_restores_tab();
?>