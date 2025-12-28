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

/**
 * Display logs tab content
 */
function wpvault_display_logs_tab()
{
    require_once WP_VAULT_PLUGIN_DIR . 'includes/class-wp-vault-api.php';

    $api = new \WP_Vault\WP_Vault_API();
    $registered = (bool) get_option('wpv_site_id');

    // Get log files from wp-content/wp-vault-logs/
    $log_dir = WP_CONTENT_DIR . '/wp-vault-logs/';
    $log_files = array();

    if (is_dir($log_dir)) {
        $files = scandir($log_dir);
        foreach ($files as $file) {
            if ($file === '.' || $file === '..' || $file === '.htaccess') {
                continue;
            }

            // Check if it's a log file (ends with _backup_log.txt or _restore_log.txt)
            if (preg_match('/^(.+)_(backup|restore)_log\.txt$/', $file, $matches)) {
                $file_path = $log_dir . $file;
                if (is_file($file_path)) {
                    $filemtime = filemtime($file_path);
                    $job_id = $matches[1];
                    $job_type = $matches[2]; // 'backup' or 'restore'

                    // Get file size
                    $file_size = filesize($file_path);

                    // Read first few lines to get log info
                    $first_lines = array();
                    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Reading log file for display
                    $handle = @fopen($file_path, 'r');
                    if ($handle) {
                        $line_count = 0;
                        while (!feof($handle) && $line_count < 5) {
                            $line = fgets($handle);
                            if ($line !== false) {
                                $first_lines[] = trim($line);
                                $line_count++;
                            }
                        }
                        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing file handle
                        fclose($handle);
                    }

                    $log_files[] = array(
                        'file' => $file,
                        'file_path' => $file_path,
                        'job_id' => $job_id,
                        'job_type' => $job_type,
                        'modified' => $filemtime,
                        'size' => $file_size,
                        'first_lines' => $first_lines,
                    );
                }
            }
        }

        // Sort by modification time (newest first)
        usort($log_files, function ($a, $b) {
            return $b['modified'] - $a['modified'];
        });
    }
    ?>

    <div class="wpv-tab-content" id="wpv-tab-logs">
        <div class="wpv-section">
            <h2><?php esc_html_e('Backup & Restore Logs', 'wp-vault'); ?></h2>

            <div class="wpv-logs-controls">
                <select id="wpv-log-filter" class="wpv-select">
                    <option value="all"><?php esc_html_e('All Logs', 'wp-vault'); ?></option>
                    <option value="backup"><?php esc_html_e('Backup Logs', 'wp-vault'); ?></option>
                    <option value="restore"><?php esc_html_e('Restore Logs', 'wp-vault'); ?></option>
                </select>

                <select id="wpv-log-severity" class="wpv-select">
                    <option value="all"><?php esc_html_e('All Severities', 'wp-vault'); ?></option>
                    <option value="ERROR"><?php esc_html_e('Errors Only', 'wp-vault'); ?></option>
                    <option value="WARNING"><?php esc_html_e('Warnings & Errors', 'wp-vault'); ?></option>
                </select>

                <button id="wpv-refresh-logs" class="button">
                    <span class="dashicons dashicons-update"></span>
                    <?php esc_html_e('Refresh', 'wp-vault'); ?>
                </button>
            </div>

            <?php if (empty($log_files)): ?>
                <div class="wpv-empty-state">
                    <p><?php esc_html_e('No logs found. Logs will appear here after you run a backup or restore.', 'wp-vault'); ?>
                    </p>
                </div>
            <?php else: ?>
                <div class="wpv-logs-container">
                    <table class="wp-list-table widefat fixed striped" id="wpv-logs-table">
                        <thead>
                            <tr>
                                <th style="width:180px;"><?php esc_html_e('Date & Time', 'wp-vault'); ?></th>
                                <th style="width:120px;"><?php esc_html_e('Type', 'wp-vault'); ?></th>
                                <th style="width:200px;"><?php esc_html_e('Job ID', 'wp-vault'); ?></th>
                                <th style="width:100px;"><?php esc_html_e('Size', 'wp-vault'); ?></th>
                                <th><?php esc_html_e('Preview', 'wp-vault'); ?></th>
                                <th style="width:120px;"><?php esc_html_e('Actions', 'wp-vault'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($log_files as $log_file): ?>
                                <tr class="wpv-log-file-row wpv-log-type-<?php echo esc_attr($log_file['job_type']); ?>"
                                    data-type="<?php echo esc_attr($log_file['job_type']); ?>"
                                    data-job-id="<?php echo esc_attr($log_file['job_id']); ?>">
                                    <td><?php echo esc_html(gmdate('M j, Y H:i:s', $log_file['modified'])); ?></td>
                                    <td>
                                        <span class="wpv-log-type-badge wpv-type-<?php echo esc_attr($log_file['job_type']); ?>">
                                            <?php echo esc_html(ucfirst($log_file['job_type'])); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <code
                                            style="font-size:11px;"><?php echo esc_html(substr($log_file['job_id'], 0, 20)); ?><?php echo strlen($log_file['job_id']) > 20 ? '...' : ''; ?></code>
                                    </td>
                                    <td><?php echo esc_html(size_format($log_file['size'])); ?></td>
                                    <td>
                                        <div
                                            style="font-size:12px; color:#666; max-width:400px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
                                            <?php
                                            if (!empty($log_file['first_lines'])) {
                                                $preview = implode(' | ', array_slice($log_file['first_lines'], 0, 2));
                                                echo esc_html($preview);
                                            } else {
                                                esc_html_e('No preview available', 'wp-vault');
                                            }
                                            ?>
                                        </div>
                                    </td>
                                    <td>
                                        <button class="button button-small wpv-view-log-file"
                                            data-file="<?php echo esc_attr($log_file['file']); ?>"
                                            data-job-id="<?php echo esc_attr($log_file['job_id']); ?>"
                                            data-job-type="<?php echo esc_attr($log_file['job_type']); ?>">
                                            <?php esc_html_e('View', 'wp-vault'); ?>
                                        </button>
                                        <a href="<?php echo esc_url(admin_url('admin-ajax.php?action=wpv_download_log&file=' . urlencode($log_file['file']) . '&nonce=' . wp_create_nonce('wp-vault'))); ?>"
                                            class="button button-small" download>
                                            <?php esc_html_e('Download', 'wp-vault'); ?>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        jQuery(document).ready(function ($) {
            // Filter logs by type
            $('#wpv-log-filter').on('change', function () {
                var filter = $(this).val();
                $('.wpv-log-file-row').each(function () {
                    if (filter === 'all' || $(this).data('type') === filter) {
                        $(this).show();
                    } else {
                        $(this).hide();
                    }
                });
            });

            // View log file
            $(document).on('click', '.wpv-view-log-file', function () {
                var file = $(this).data('file');
                var jobId = $(this).data('job-id');
                var jobType = $(this).data('job-type');

                // Reuse the existing modal from dashboard (it should already exist)
                // If it doesn't exist, create it
                if ($('#wpv-logs-modal').length === 0) {
                    $('body').append('<div id="wpv-logs-modal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999; overflow-y:auto;"><div style="background:#fff; width:900px; max-width:95vw; max-height:90vh; margin:30px auto; padding:25px; border-radius:5px; box-shadow:0 0 20px rgba(0,0,0,0.3); display:flex; flex-direction:column; position:relative;"><div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px; border-bottom:1px solid #e2e4e7; padding-bottom:15px;"><h3 style="margin:0; font-size:18px;"><?php echo esc_js(esc_html__('Logs', 'wp-vault')); ?></h3><button class="button" id="wpv-close-logs-modal"><?php echo esc_js(esc_html__('Close', 'wp-vault')); ?></button></div><div style="margin-bottom:15px; display:flex; justify-content:space-between; align-items:center;"><span id="wpv-logs-backup-id" style="color:#666; font-size:13px; font-family:monospace;"></span></div><div id="wpv-logs-content" style="background:#1e1e1e; color:#d4d4d4; border:1px solid #3c3c3c; border-radius:4px; height:500px; overflow:auto; padding:15px; font-family:\'Courier New\', monospace; font-size:12px; flex:1; line-height:1.6; white-space:pre-wrap; word-wrap:break-word;"></div></div></div>');
                }

                $('#wpv-logs-modal').show();
                $('#wpv-logs-backup-id').text(ucfirst(jobType) + ' ID: ' + jobId);
                $('#wpv-logs-content').html('<div style="color:#888;"><?php echo esc_js(esc_html__('Loading logs...', 'wp-vault')); ?></div>');

                // Load log file via AJAX
                $.post(ajaxurl, {
                    action: 'wpv_read_log',
                    file: file,
                    lines: 0, // 0 means all lines in read_log
                    nonce: wpVault.nonce
                }, function (response) {
                    if (response.success && response.data && response.data.content) {
                        // Format log content - preserve line breaks and escape HTML
                        var content = response.data.content;
                        // The modal already has white-space:pre-wrap, so we can format it properly for the dark background
                        var lines = content.split('\n');
                        var html = '';
                        lines.forEach(function (line) {
                            if (line.trim()) {
                                // Parse log line: [timestamp][level] message
                                var match = line.match(/^\[([^\]]+)\]\[([^\]]+)\]\s*(.+)$/);
                                if (match) {
                                    var timestamp = match[1];
                                    var level = match[2];
                                    var message = escapeHtml(match[3]);
                                    var levelColor = level === 'ERROR' ? '#f48771' : (level === 'WARNING' ? '#dcdcaa' : '#4ec9b0');
                                    html += '<div style="margin-bottom:2px;"><span style="color:#858585;">[' + escapeHtml(timestamp) + ']</span> <span style="color:' + levelColor + '; font-weight:bold;">[' + level + ']</span> <span style="color:#d4d4d4;">' + message + '</span></div>';
                                } else {
                                    // Plain text line
                                    html += '<div style="margin-bottom:2px; color:#d4d4d4;">' + escapeHtml(line) + '</div>';
                                }
                            } else {
                                html += '<br>';
                            }
                        });
                        $('#wpv-logs-content').html(html || '<div style="color:#888;"><?php echo esc_js(esc_html__('No log content found.', 'wp-vault')); ?></div>');
                        // Scroll to bottom
                        var $content = $('#wpv-logs-content');
                        $content.scrollTop($content[0].scrollHeight);
                    } else {
                        var errorMsg = response.data && response.data.message ? response.data.message : '<?php echo esc_js(esc_html__('Error loading log file.', 'wp-vault')); ?>';
                        $('#wpv-logs-content').html('<div style="color:#f48771;">' + escapeHtml(errorMsg) + '</div>');
                    }
                }).fail(function (xhr, status, error) {
                    var errorMsg = '<?php echo esc_js(esc_html__('Error loading log file.', 'wp-vault')); ?>';
                    if (xhr.responseText) {
                        try {
                            var errorResponse = JSON.parse(xhr.responseText);
                            if (errorResponse.data && errorResponse.data.message) {
                                errorMsg = errorResponse.data.message;
                            }
                        } catch (e) {
                            // Ignore JSON parse errors
                        }
                    }
                    $('#wpv-logs-content').html('<div style="color:#f48771;">' + escapeHtml(errorMsg) + '</div>');
                });
            });

            // Close modal
            $(document).on('click', '#wpv-close-logs-modal, #wpv-logs-modal', function (e) {
                if (e.target === this || $(e.target).is('#wpv-close-logs-modal')) {
                    $('#wpv-logs-modal').hide();
                }
            });

            // Prevent modal close when clicking inside
            $(document).on('click', '#wpv-logs-modal > div', function (e) {
                e.stopPropagation();
            });

            // Refresh logs
            $('#wpv-refresh-logs').on('click', function () {
                location.reload();
            });

            // Helper function to escape HTML
            function escapeHtml(text) {
                var map = {
                    '&': '&amp;',
                    '<': '&lt;',
                    '>': '&gt;',
                    '"': '&quot;',
                    "'": '&#039;'
                };
                return text.replace(/[&<>"']/g, function (m) { return map[m]; });
            }

            // Helper function to capitalize first letter
            function ucfirst(str) {
                return str.charAt(0).toUpperCase() + str.slice(1);
            }
        });
    </script>

    <?php
}

// Call the function to display the tab
wpvault_display_logs_tab();
?>