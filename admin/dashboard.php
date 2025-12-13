<?php
/**
 * WP Vault Dashboard Page
 *
 * @package WP_Vault
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once WP_VAULT_PLUGIN_DIR . 'includes/class-wp-vault-api.php';

$api = new \WP_Vault\WP_Vault_API();
$backups_result = $api->get_backups();
$backups = $backups_result['success'] ? $backups_result['data']['backups'] : array();
?>

<div class="wrap">
    <h1><?php _e('WP Vault Dashboard', 'wp-vault'); ?></h1>

    <?php if (!get_option('wpv_site_id')): ?>
        <!-- Not registered -->
        <div class="notice notice-warning">
            <p><?php _e('Please configure WP Vault in Settings to start backing up your site.', 'wp-vault'); ?></p>
            <p><a href="<?php echo admin_url('admin.php?page=wp-vault-settings'); ?>"
                    class="button button-primary"><?php _e('Go to Settings', 'wp-vault'); ?></a></p>
        </div>
    <?php else: ?>
        <!-- Registered -->
        <div class="wpv-dashboard">
            <!-- Quick Actions -->
            <div class="wpv-actions" style="margin: 20px 0;">
                <button id="wpv-backup-now" class="button button-primary button-large">
                    <span class="dashicons dashicons-cloud-upload"></span>
                    <?php _e('Backup Now', 'wp-vault'); ?>
                </button>
                <select id="wpv-backup-type" style="margin-left: 10px;">
                    <option value="full"><?php _e('Full Backup', 'wp-vault'); ?></option>
                    <option value="files"><?php _e('Files Only', 'wp-vault'); ?></option>
                    <option value="database"><?php _e('Database Only', 'wp-vault'); ?></option>
                </select>
            </div>

            <!-- Backups List -->
            <h2><?php _e('Recent Backups', 'wp-vault'); ?></h2>
            <?php if (empty($backups)): ?>
                <p><?php _e('No backups yet. Click "Backup Now" to create your first backup.', 'wp-vault'); ?></p>
            <?php else: ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php _e('Backup ID', 'wp-vault'); ?></th>
                            <th><?php _e('Type', 'wp-vault'); ?></th>
                            <th><?php _e('Status', 'wp-vault'); ?></th>
                            <th><?php _e('Size', 'wp-vault'); ?></th>
                            <th><?php _e('Date', 'wp-vault'); ?></th>
                            <th><?php _e('Actions', 'wp-vault'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($backups as $backup): ?>
                            <tr>
                                <td><code><?php echo esc_html(substr($backup['id'], 0, 8)); ?></code></td>
                                <td><?php echo esc_html(ucfirst($backup['backup_type'])); ?></td>
                                <td>
                                    <span class="wpv-status wpv-status-<?php echo esc_attr($backup['status']); ?>">
                                        <?php echo esc_html(ucfirst($backup['status'])); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php
                                    if ($backup['total_size_bytes']) {
                                        echo size_format($backup['total_size_bytes']);
                                    } else {
                                        echo '—';
                                    }
                                    ?>
                                </td>
                                <td><?php echo esc_html(mysql2date('M j, Y g:i a', $backup['created_at'])); ?></td>
                                <td>
                                    <button class="button button-small wpv-show-logs"
                                        data-backup-id="<?php echo esc_attr($backup['id']); ?>">
                                        <?php _e('Show Logs', 'wp-vault'); ?>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <style>
            .wpv-status {
                padding: 4px 8px;
                border-radius: 3px;
                font-size: 12px;
                font-weight: 600;
            }

            .wpv-status-success {
                background: #d4edda;
                color: #155724;
            }

            .wpv-status-pending {
                background: #fff3cd;
                color: #856404;
            }

            .wpv-status-running {
                background: #d1ecf1;
                color: #0c5460;
            }

            .wpv-status-failed {
                background: #f8d7da;
                color: #721c24;
            }

            .wpv-status-completed {
                background: #d4edda;
                color: #155724;
            }
        </style>

        <!-- Logs Modal -->
        <div id="wpv-logs-modal"
            style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999; overflow-y:auto;">
            <div
                style="background:#fff; width:900px; max-width:95vw; max-height:90vh; margin:30px auto; padding:25px; border-radius:5px; box-shadow:0 0 20px rgba(0,0,0,0.3); display:flex; flex-direction:column; position:relative;">
                <div
                    style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px; border-bottom:1px solid #e2e4e7; padding-bottom:15px;">
                    <h3 style="margin:0; font-size:18px;"><?php _e('Backup Logs', 'wp-vault'); ?></h3>
                    <button class="button" id="wpv-close-logs-modal"><?php _e('Close', 'wp-vault'); ?></button>
                </div>
                <div style="margin-bottom:15px; display:flex; justify-content:space-between; align-items:center;">
                    <span id="wpv-logs-backup-id" style="color:#666; font-size:13px; font-family:monospace;"></span>
                    <button class="button button-primary button-small" id="wpv-download-logs-btn" style="display:none;">
                        <span class="dashicons dashicons-download"
                            style="font-size:16px; line-height:1.2; margin-right:4px;"></span>
                        <?php _e('Download Log', 'wp-vault'); ?>
                    </button>
                </div>
                <div id="wpv-logs-content"
                    style="background:#1e1e1e; color:#d4d4d4; border:1px solid #3c3c3c; border-radius:4px; height:500px; overflow:auto; padding:15px; font-family:'Courier New', monospace; font-size:12px; flex:1; line-height:1.6; white-space:pre-wrap; word-wrap:break-word;">
                    <div style="color:#888;"><?php _e('Loading logs...', 'wp-vault'); ?></div>
                </div>
            </div>
        </div>

        <!-- Progress Modal -->
        <div id="wpv-progress-modal"
            style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999;">
            <div
                style="background:#fff; width:500px; margin:100px auto; padding:20px; border-radius:5px; box-shadow:0 0 10px rgba(0,0,0,0.3);">
                <h3 style="margin-top:0;"><?php _e('Backup in Progress...', 'wp-vault'); ?></h3>

                <div class="wpv-progress-bar"
                    style="background:#f0f0f0; height:20px; border-radius:10px; overflow:hidden; margin:15px 0;">
                    <div id="wpv-progress-fill" style="background:#2271b1; height:100%; width:0%; transition:width 0.3s;">
                    </div>
                </div>

                <div id="wpv-progress-text" style="font-weight:bold; text-align:center; margin-bottom:10px;">0%</div>
                <div id="wpv-progress-message" style="color:#666; font-style:italic; text-align:center;">Initializing...
                </div>

                <div id="wpv-log-feed"
                    style="background:#f8f9fa; border:1px solid #e2e4e7; border-radius:4px; height:180px; overflow:auto; padding:10px; font-family:monospace; font-size:12px; margin-top:12px;">
                    <div style="color:#888;">Waiting for logs...</div>
                </div>

                <div id="wpv-modal-actions" style="margin-top:20px; text-align:right; display:none;">
                    <button class="button" onclick="$('#wpv-progress-modal').hide();"
                        style="margin-right:10px;"><?php _e('Close', 'wp-vault'); ?></button>
                    <button class="button button-primary"
                        onclick="location.reload()"><?php _e('Close & Refresh', 'wp-vault'); ?></button>
                </div>
            </div>
        </div>

        <script>
            jQuery(document).ready(function ($) {
                var currentLogFile = null;

                // Show logs modal
                $(document).on('click', '.wpv-show-logs', function () {
                    var backupId = $(this).data('backup-id');
                    $('#wpv-logs-modal').show();
                    $('#wpv-logs-backup-id').text('Backup ID: ' + backupId);
                    $('#wpv-logs-content').html('<div style="color:#888;"><?php _e('Loading logs...', 'wp-vault'); ?></div>');
                    $('#wpv-download-logs-btn').hide();
                    currentLogFile = null;

                    // Get backup status to find log file path
                    $.post(wpVault.ajax_url, {
                        action: 'wpv_get_backup_status',
                        backup_id: backupId,
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
                                $('#wpv-logs-content').html('<div style="color:#d63638;"><?php _e('No logs found for this backup.', 'wp-vault'); ?></div>');
                            }
                        }
                    }).fail(function () {
                        $('#wpv-logs-content').html('<div style="color:#d63638;"><?php _e('Error loading logs.', 'wp-vault'); ?></div>');
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
                            $('#wpv-logs-content').html(html || '<div style="color:#888;"><?php _e('No log content found.', 'wp-vault'); ?></div>');
                            var $content = $('#wpv-logs-content');
                            $content.scrollTop($content[0].scrollHeight);
                        } else {
                            $('#wpv-logs-content').html('<div style="color:#d63638;"><?php _e('Error reading log file.', 'wp-vault'); ?></div>');
                        }
                    }).fail(function () {
                        $('#wpv-logs-content').html('<div style="color:#d63638;"><?php _e('Error loading logs.', 'wp-vault'); ?></div>');
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
                    $('#wpv-logs-content').html(html || '<div style="color:#888;"><?php _e('No logs available.', 'wp-vault'); ?></div>');
                    var $content = $('#wpv-logs-content');
                    $content.scrollTop($content[0].scrollHeight);
                }

                $('#wpv-backup-now').on('click', function () {
                    var $btn = $(this);
                    var backupType = $('#wpv-backup-type').val();

                    // Show modal
                    $('#wpv-progress-modal').show();
                    $('#wpv-progress-fill').css('width', '0%');
                    $('#wpv-progress-text').text('0%');
                    $('#wpv-progress-message').text('<?php _e('Starting backup...', 'wp-vault'); ?>');
                    $('#wpv-log-feed').html('<div style="color:#888;"><?php _e('Waiting for logs...', 'wp-vault'); ?></div>');
                    $('#wpv-modal-actions').hide();

                    $.post(wpVault.ajax_url, {
                        action: 'wpv_trigger_backup',
                        backup_type: backupType,
                        nonce: wpVault.nonce
                    }, function (response) {
                        if (response.success) {
                            var backupId = response.data.backup_id;
                            pollProgress(backupId);
                        } else {
                            alert('<?php _e('Backup failed to start:', 'wp-vault'); ?> ' + (response.data.error || '<?php _e('Unknown error', 'wp-vault'); ?>'));
                            $('#wpv-progress-modal').hide();
                        }
                    }).fail(function () {
                        alert('<?php _e('Network error starting backup', 'wp-vault'); ?>');
                        $('#wpv-progress-modal').hide();
                    });
                });

                function pollProgress(backupId) {
                    var pollInterval = setInterval(function () {
                        $.post(wpVault.ajax_url, {
                            action: 'wpv_get_backup_status',
                            backup_id: backupId,
                            nonce: wpVault.nonce
                        }, function (response) {
                            if (response.success) {
                                var status = response.data.status;
                                var progress = response.data.progress;
                                var message = response.data.message;
                                var logs = response.data.logs || [];

                                $('#wpv-progress-fill').css('width', progress + '%');
                                $('#wpv-progress-text').text(progress + '%');
                                $('#wpv-progress-message').text(status + (message ? ': ' + message : ''));
                                renderLogs(logs);

                                // Check if backup is complete (check logs for completion message or status)
                                var isComplete = false;
                                if (logs && logs.length > 0) {
                                    var lastLog = logs[logs.length - 1];
                                    isComplete = lastLog.message && (
                                        lastLog.message.toLowerCase().includes('complete') ||
                                        lastLog.message.toLowerCase().includes('finished')
                                    );
                                }

                                if (status === 'completed' || status === 'failed' || (progress >= 100 && isComplete)) {
                                    clearInterval(pollInterval);
                                    $('#wpv-modal-actions').show();

                                    if (status === 'completed' || (progress >= 100 && isComplete && status !== 'failed')) {
                                        $('#wpv-progress-fill').css('background', '#46b450'); // Green
                                        $('#wpv-progress-message').text('<?php _e('Backup completed successfully!', 'wp-vault'); ?>');
                                        // Auto-close after 3 seconds
                                        setTimeout(function () {
                                            $('#wpv-progress-modal').hide();
                                            location.reload();
                                        }, 3000);
                                    } else {
                                        $('#wpv-progress-fill').css('background', '#dc3232'); // Red
                                    }
                                }
                            }
                        });
                    }, 1000); // Poll every 1 second
                }

                function renderLogs(logs) {
                    var $feed = $('#wpv-log-feed');
                    if (!logs || logs.length === 0) {
                        $feed.html('<div style="color:#888;"><?php _e('No logs yet...', 'wp-vault'); ?></div>');
                        return;
                    }

                    var html = logs.map(function (log) {
                        var time = log.created_at ? new Date(log.created_at).toLocaleTimeString() : '';
                        var severity = log.severity || 'INFO';
                        return '<div><span style="color:#666;">[' + time + ']</span> <span style="color:' + (severity === 'ERROR' ? '#d63638' : '#2271b1') + ';">' + severity + '</span> ' + $('<div/>').text(log.message).html() + '</div>';
                    }).join('');

                    $feed.html(html);
                    $feed.scrollTop($feed[0].scrollHeight);
                }
            });
        </script>
    <?php endif; ?>
</div>