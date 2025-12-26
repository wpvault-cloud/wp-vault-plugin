<?php
/**
 * WP Vault Restores Page
 *
 * @package WP_Vault
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Display restores page
 */
function wpvault_display_restores_page() {
    // Get restore jobs from database
    global $wpdb;
    $table = $wpdb->prefix . 'wp_vault_jobs';
    $table_escaped = esc_sql($table);
    $restores = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$table_escaped} WHERE job_type = %s ORDER BY created_at DESC LIMIT 50",
        'restore'
    ));
    ?>

    <div class="wrap">
    <h1><?php esc_html_e('WP Vault Restores', 'wp-vault'); ?></h1>

    <?php if (empty($restores)): ?>
        <div class="notice notice-info">
            <p><?php esc_html_e('No restore history yet. Restores will appear here after you restore a backup.', 'wp-vault'); ?></p>
        </div>
    <?php else: ?>
        <?php /* translators: %d: number of restores */ ?>
        <p><?php printf(esc_html__('Found %d restore(s) in history', 'wp-vault'), count($restores)); ?></p>

        <table class="wp-list-table widefat fixed striped">
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
                    <tr>
                        <td><code><?php echo esc_html(substr($restore->backup_id, 0, 20)); ?></code></td>
                        <td>
                            <span class="wpv-status wpv-status-<?php echo esc_attr($restore->status); ?>">
                                <?php echo esc_html(ucfirst($restore->status)); ?>
                            </span>
                        </td>
                        <td>
                            <?php
                            if ($restore->status === 'running' || $restore->status === 'completed') {
                                echo esc_html($restore->progress_percent) . '%';
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
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <style>
        .wpv-status {
            padding: 4px 8px;
            border-radius: 3px;
            font-size: 12px;
            font-weight: 600;
        }

        .wpv-status-completed {
            background: #d4edda;
            color: #155724;
        }

        .wpv-status-running {
            background: #d1ecf1;
            color: #0c5460;
        }

        .wpv-status-failed {
            background: #f8d7da;
            color: #721c24;
        }

        .wpv-status-pending {
            background: #fff3cd;
            color: #856404;
        }
    </style>

    <!-- Logs Modal (same as dashboard) -->
    <div id="wpv-logs-modal"
        style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999; overflow-y:auto;">
        <div
            style="background:#fff; width:900px; max-width:95vw; max-height:90vh; margin:30px auto; padding:25px; border-radius:5px; box-shadow:0 0 20px rgba(0,0,0,0.3); display:flex; flex-direction:column; position:relative;">
            <div
                style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px; border-bottom:1px solid #e2e4e7; padding-bottom:15px;">
                <h3 style="margin:0; font-size:18px;"><?php esc_html_e('Restore Logs', 'wp-vault'); ?></h3>
                <button class="button" id="wpv-close-logs-modal"><?php esc_html_e('Close', 'wp-vault'); ?></button>
            </div>
            <div style="margin-bottom:15px; display:flex; justify-content:space-between; align-items:center;">
                <span id="wpv-logs-restore-id" style="color:#666; font-size:13px; font-family:monospace;"></span>
                <button class="button button-primary button-small" id="wpv-download-logs-btn" style="display:none;">
                    <span class="dashicons dashicons-download"
                        style="font-size:16px; line-height:1.2; margin-right:4px;"></span>
                    <?php esc_html_e('Download Log', 'wp-vault'); ?>
                </button>
            </div>
            <div id="wpv-logs-content"
                style="background:#1e1e1e; color:#d4d4d4; border:1px solid #3c3c3c; border-radius:4px; height:500px; overflow:auto; padding:15px; font-family:'Courier New', monospace; font-size:12px; flex:1; line-height:1.6; white-space:pre-wrap; word-wrap:break-word;">
                <div style="color:#888;"><?php esc_html_e('Loading logs...', 'wp-vault'); ?></div>
            </div>
        </div>
    </div>

    <script>
        jQuery(document).ready(function ($) {
            var currentLogFile = null;

            // Show logs modal for restores
            $(document).on('click', '.wpv-show-restore-logs', function () {
                var restoreId = $(this).data('restore-id');
                $('#wpv-logs-modal').show();
                $('#wpv-logs-restore-id').text('Restore ID: ' + restoreId);
                $('#wpv-logs-content').html('<div style="color:#888;"><?php echo esc_js(esc_html__('Loading logs...', 'wp-vault')); ?></div>');
                $('#wpv-download-logs-btn').hide();
                currentLogFile = null;

                // Get restore status to find log file path
                $.post(wpVault.ajax_url, {
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

            // Close logs modal
            $('#wpv-close-logs-modal, #wpv-logs-modal').on('click', function (e) {
                if (e.target === this || $(e.target).is('#wpv-close-logs-modal')) {
                    $('#wpv-logs-modal').hide();
                    currentLogFile = null;
                }
            });

            // Prevent modal content clicks from closing modal
            $('#wpv-logs-modal > div').on('click', function (e) {
                e.stopPropagation();
            });

            // Download logs
            $('#wpv-download-logs-btn').on('click', function () {
                if (currentLogFile) {
                    window.location.href = wpVault.ajax_url + '?action=wpv_download_log&log_file=' + encodeURIComponent(currentLogFile) + '&nonce=' + wpVault.nonce;
                }
            });

            // Load logs from file
            function loadLogs(logFilePath) {
                $.post(wpVault.ajax_url, {
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
        });
    </script>
</div>
    <?php
}

// Call the function to display the restores page
wpvault_display_restores_page();
?>