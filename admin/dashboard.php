<?php
/**
 * WP Vault Main Dashboard Page
 * 
 * Unified tabbed interface for all WP Vault functionality
 *
 * @package WP_Vault
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Display main dashboard page
 */
function wpvault_display_dashboard_page()
{
    // Get current tab from URL or default based on page
    $page = isset($_GET['page']) ? sanitize_text_field($_GET['page']) : '';
    $current_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : '';

    // If no tab specified, determine from page
    if (empty($current_tab)) {
        if ($page === 'wp-vault-backups') {
            $current_tab = 'backups';
        } elseif ($page === 'wp-vault-restores') {
            $current_tab = 'restores';
        } elseif ($page === 'wp-vault-storage' || $page === 'wp-vault-settings') {
            // Settings menu item now points to Storage tab
            $current_tab = 'storage';
        } else {
            $current_tab = 'dashboard'; // Default to dashboard
        }
    }

    $valid_tabs = array('dashboard', 'backups', 'restores', 'schedule', 'logs', 'features', 'settings', 'storage');
    if (!in_array($current_tab, $valid_tabs)) {
        $current_tab = 'dashboard';
    }

    $plugin_version = WP_VAULT_VERSION;
    ?>

    <div class="wrap wpv-dashboard-wrapper">
        <!-- Header -->
        <div class="wpv-header">
            <div class="wpv-header-left">
                <h1 class="wpv-page-title">
                    <img src="<?php echo esc_url(WP_VAULT_PLUGIN_URL . 'assets/images/logo.svg'); ?>" alt="WP Vault"
                        class="wpv-logo" style="width: 32px; height: 32px; margin-right: 10px; vertical-align: middle;" />
                    <?php esc_html_e('WP Vault', 'wp-vault'); ?>
                </h1>
            </div>
            <div class="wpv-header-right">
                <span class="wpv-version-badge">v<?php echo esc_html($plugin_version); ?></span>
            </div>
        </div>

        <!-- Tab Navigation -->
        <div class="wpv-tabs">
            <a href="<?php echo esc_url(admin_url('admin.php?page=wp-vault&tab=dashboard')); ?>"
                class="wpv-tab <?php echo $current_tab === 'dashboard' ? 'wpv-tab-active' : ''; ?>">
                <?php esc_html_e('Dashboard', 'wp-vault'); ?>
            </a>
            <a href="<?php echo esc_url(admin_url('admin.php?page=wp-vault&tab=backups')); ?>"
                class="wpv-tab <?php echo $current_tab === 'backups' ? 'wpv-tab-active' : ''; ?>">
                <?php esc_html_e('Backups', 'wp-vault'); ?>
            </a>
            <a href="<?php echo esc_url(admin_url('admin.php?page=wp-vault&tab=restores')); ?>"
                class="wpv-tab <?php echo $current_tab === 'restores' ? 'wpv-tab-active' : ''; ?>">
                <?php esc_html_e('Restores', 'wp-vault'); ?>
            </a>
            <a href="<?php echo esc_url(admin_url('admin.php?page=wp-vault&tab=schedule')); ?>"
                class="wpv-tab <?php echo $current_tab === 'schedule' ? 'wpv-tab-active' : ''; ?>">
                <?php esc_html_e('Schedule', 'wp-vault'); ?>
            </a>
            <a href="<?php echo esc_url(admin_url('admin.php?page=wp-vault&tab=logs')); ?>"
                class="wpv-tab <?php echo $current_tab === 'logs' ? 'wpv-tab-active' : ''; ?>">
                <?php esc_html_e('Logs', 'wp-vault'); ?>
            </a>
            <a href="<?php echo esc_url(admin_url('admin.php?page=wp-vault&tab=features')); ?>"
                class="wpv-tab <?php echo $current_tab === 'features' ? 'wpv-tab-active' : ''; ?>">
                <?php esc_html_e('Features', 'wp-vault'); ?>
            </a>
            <a href="<?php echo esc_url(admin_url('admin.php?page=wp-vault&tab=settings')); ?>"
                class="wpv-tab <?php echo $current_tab === 'settings' ? 'wpv-tab-active' : ''; ?>">
                <?php esc_html_e('Settings', 'wp-vault'); ?>
            </a>
            <a href="<?php echo esc_url(admin_url('admin.php?page=wp-vault&tab=storage')); ?>"
                class="wpv-tab <?php echo $current_tab === 'storage' ? 'wpv-tab-active' : ''; ?>">
                <?php esc_html_e('Storage', 'wp-vault'); ?>
            </a>
        </div>

        <!-- Main Content Area -->
        <div class="wpv-content-wrapper">
            <!-- Left Column: Tab Content -->
            <div class="wpv-content-main">
                <?php
                // Load appropriate tab content
                switch ($current_tab) {
                    case 'dashboard':
                        include WP_VAULT_PLUGIN_DIR . 'admin/partials/tab-dashboard.php';
                        break;
                    case 'backups':
                        include WP_VAULT_PLUGIN_DIR . 'admin/partials/tab-backups.php';
                        break;
                    case 'restores':
                        include WP_VAULT_PLUGIN_DIR . 'admin/partials/tab-restores.php';
                        break;
                    case 'schedule':
                        include WP_VAULT_PLUGIN_DIR . 'admin/partials/tab-schedule.php';
                        break;
                    case 'logs':
                        include WP_VAULT_PLUGIN_DIR . 'admin/partials/tab-logs.php';
                        break;
                    case 'features':
                        include WP_VAULT_PLUGIN_DIR . 'admin/partials/tab-features.php';
                        break;
                    case 'settings':
                        include WP_VAULT_PLUGIN_DIR . 'admin/partials/tab-settings.php';
                        break;
                    case 'storage':
                        include WP_VAULT_PLUGIN_DIR . 'admin/partials/tab-storage.php';
                        break;
                    default:
                        include WP_VAULT_PLUGIN_DIR . 'admin/partials/tab-dashboard.php';
                        break;
                }
                ?>
            </div>

            <!-- Right Column: Sidebar -->
            <div class="wpv-content-sidebar">
                <?php
                // Show connection status and storage usage on Dashboard and Backups tabs
                if ($current_tab === 'dashboard' || $current_tab === 'backups') {
                    include WP_VAULT_PLUGIN_DIR . 'admin/partials/connection-status.php';
                }

                // Always show system info
                include WP_VAULT_PLUGIN_DIR . 'admin/partials/system-info.php';
                ?>
            </div>
        </div>
    </div>

    <!-- Modals (shared across tabs) -->
    <!-- Backup Progress Modal -->
    <div id="wpv-progress-modal"
        style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999;">
        <div
            style="background:#fff; width:500px; margin:100px auto; padding:20px; border-radius:5px; box-shadow:0 0 10px rgba(0,0,0,0.3);">
            <h3 style="margin-top:0;"><?php esc_html_e('Backup in Progress...', 'wp-vault'); ?></h3>
            <div class="wpv-progress-bar"
                style="background:#f0f0f0; height:20px; border-radius:10px; overflow:hidden; margin:15px 0;">
                <div id="wpv-progress-fill" style="background:#2271b1; height:100%; width:0%; transition:width 0.3s;"></div>
            </div>
            <div id="wpv-progress-text" style="font-weight:bold; text-align:center; margin-bottom:10px;">0%</div>
            <div id="wpv-progress-message" style="color:#666; font-style:italic; text-align:center;">Initializing...</div>
            <div id="wpv-log-feed"
                style="background:#f8f9fa; border:1px solid #e2e4e7; border-radius:4px; height:180px; overflow:auto; padding:10px; font-family:monospace; font-size:12px; margin-top:12px;">
                <div style="color:#888;"><?php esc_html_e('Waiting for logs...', 'wp-vault'); ?></div>
            </div>
            <div id="wpv-modal-actions" style="margin-top:20px; text-align:right; display:none;">
                <button class="button" onclick="jQuery('#wpv-progress-modal').hide();"
                    style="margin-right:10px;"><?php esc_html_e('Close', 'wp-vault'); ?></button>
                <button class="button button-primary"
                    onclick="location.reload()"><?php esc_html_e('Close & Refresh', 'wp-vault'); ?></button>
            </div>
        </div>
    </div>

    <!-- Restore Options Modal -->
    <div id="wpv-restore-options-modal"
        style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999;">
        <div
            style="background:#fff; width:600px; max-height:90vh; overflow-y:auto; margin:50px auto; padding:20px; border-radius:5px; box-shadow:0 0 10px rgba(0,0,0,0.3);">
            <h3 style="margin-top:0;"><?php esc_html_e('Restore Options', 'wp-vault'); ?></h3>
            <p style="color:#666; margin-top:5px;">
                <?php esc_html_e('Select what you want to restore and configure restore options.', 'wp-vault'); ?>
            </p>
            <div style="margin:20px 0;">
                <h4 style="margin-bottom:10px;"><?php esc_html_e('Components to Restore', 'wp-vault'); ?></h4>
                <div style="margin-left:10px;">
                    <label style="display:block; margin:8px 0;">
                        <input type="checkbox" name="restore_component" value="database" checked style="margin-right:8px;">
                        <strong><?php esc_html_e('Database', 'wp-vault'); ?></strong>
                    </label>
                    <label style="display:block; margin:8px 0;">
                        <input type="checkbox" name="restore_component" value="themes" checked style="margin-right:8px;">
                        <strong><?php esc_html_e('Themes', 'wp-vault'); ?></strong>
                    </label>
                    <label style="display:block; margin:8px 0;">
                        <input type="checkbox" name="restore_component" value="plugins" checked style="margin-right:8px;">
                        <strong><?php esc_html_e('Plugins', 'wp-vault'); ?></strong>
                    </label>
                    <label style="display:block; margin:8px 0;">
                        <input type="checkbox" name="restore_component" value="uploads" checked style="margin-right:8px;">
                        <strong><?php esc_html_e('Uploads', 'wp-vault'); ?></strong>
                    </label>
                    <label style="display:block; margin:8px 0;">
                        <input type="checkbox" name="restore_component" value="wp-content" checked
                            style="margin-right:8px;">
                        <strong><?php esc_html_e('WP-Content (Other)', 'wp-vault'); ?></strong>
                    </label>
                </div>
            </div>
            <div style="margin:20px 0; padding-top:20px; border-top:1px solid #e2e4e7;">
                <h4 style="margin-bottom:10px;"><?php esc_html_e('Advanced Options', 'wp-vault'); ?></h4>
                <div style="margin-left:10px;">
                    <label style="display:block; margin:8px 0;">
                        <input type="checkbox" name="restore_option" value="pre_restore_backup" checked
                            style="margin-right:8px;">
                        <strong><?php esc_html_e('Create Pre-Restore Backup', 'wp-vault'); ?></strong>
                        <span style="color:#666; font-size:12px;"> - <?php esc_html_e('Recommended', 'wp-vault'); ?></span>
                    </label>
                </div>
            </div>
            <div style="margin-top:25px; text-align:right; border-top:1px solid #e2e4e7; padding-top:15px;">
                <button type="button" class="button" id="wpv-cancel-restore-options"
                    style="margin-right:10px;"><?php esc_html_e('Cancel', 'wp-vault'); ?></button>
                <button type="button" class="button button-primary"
                    id="wpv-confirm-restore-options"><?php esc_html_e('Start Restore', 'wp-vault'); ?></button>
            </div>
        </div>
    </div>

    <!-- Delete Backup Confirmation Modal -->
    <div id="wpv-delete-backup-modal"
        style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999;">
        <div
            style="background:#fff; width:500px; margin:100px auto; padding:20px; border-radius:5px; box-shadow:0 0 10px rgba(0,0,0,0.3);">
            <h3 style="margin-top:0; color:#d63638;"><?php esc_html_e('Delete Backup', 'wp-vault'); ?></h3>
            <p style="color:#666; margin-top:10px; margin-bottom:15px;">
                <?php esc_html_e('This action will permanently delete the backup and all its components from your local storage.', 'wp-vault'); ?>
            </p>

            <!-- Warning Note -->
            <div style="background:#fff3cd; border:1px solid #ffc107; border-radius:4px; padding:12px; margin:15px 0;">
                <p style="margin:0; color:#856404; font-size:13px; line-height:1.5;">
                    <strong style="color:#d63638;">⚠ <?php esc_html_e('Important:', 'wp-vault'); ?></strong><br>
                    <?php esc_html_e('This will only delete local backups. Remote backups stored in the cloud are not affected. You must delete remote backups manually from your storage provider for security reasons.', 'wp-vault'); ?>
                </p>
            </div>

            <p style="margin:15px 0 10px 0; font-weight:bold;">
                <?php esc_html_e('Type "DELETE" to confirm:', 'wp-vault'); ?>
            </p>
            <input type="text" id="wpv-delete-confirm-input"
                placeholder="<?php esc_attr_e('Type DELETE to confirm', 'wp-vault'); ?>"
                style="width:100%; padding:8px; border:1px solid #ddd; border-radius:4px; font-size:14px; margin-bottom:15px;"
                autocomplete="off">

            <div style="margin-top:20px; text-align:right; border-top:1px solid #e2e4e7; padding-top:15px;">
                <button type="button" class="button" id="wpv-cancel-delete-backup"
                    style="margin-right:10px;"><?php esc_html_e('Cancel', 'wp-vault'); ?></button>
                <button type="button" class="button button-primary" id="wpv-confirm-delete-backup"
                    style="background:#d63638; border-color:#d63638;"
                    disabled><?php esc_html_e('Delete Backup', 'wp-vault'); ?></button>
            </div>
        </div>
    </div>

    <!-- Restore Progress Modal -->
    <div id="wpv-restore-modal"
        style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999;">
        <div
            style="background:#fff; width:500px; margin:100px auto; padding:20px; border-radius:5px; box-shadow:0 0 10px rgba(0,0,0,0.3);">
            <h3 style="margin-top:0;"><?php esc_html_e('Restore in Progress...', 'wp-vault'); ?></h3>
            <div class="wpv-progress-bar"
                style="background:#f0f0f0; height:20px; border-radius:10px; overflow:hidden; margin:15px 0;">
                <div id="wpv-restore-progress-fill"
                    style="background:#2271b1; height:100%; width:0%; transition:width 0.3s;"></div>
            </div>
            <div id="wpv-restore-progress-text" style="font-weight:bold; text-align:center; margin-bottom:10px;">0%</div>
            <div id="wpv-restore-progress-message" style="color:#666; font-style:italic; text-align:center;">Initializing...
            </div>
            <div id="wpv-restore-log-feed"
                style="background:#f8f9fa; border:1px solid #e2e4e7; border-radius:4px; height:180px; overflow:auto; padding:10px; font-family:monospace; font-size:12px; margin-top:12px;">
                <div style="color:#888;"><?php esc_html_e('Waiting for restore logs...', 'wp-vault'); ?></div>
            </div>
            <div id="wpv-restore-modal-actions" style="margin-top:20px; text-align:right; display:none;">
                <button class="button button-primary"
                    onclick="location.reload()"><?php esc_html_e('Close & Refresh', 'wp-vault'); ?></button>
            </div>
        </div>
    </div>

    <!-- Download from Remote Progress Modal -->
    <div id="wpv-download-modal"
        style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999;">
        <div
            style="background:#fff; width:600px; margin:50px auto; padding:20px; border-radius:5px; box-shadow:0 0 10px rgba(0,0,0,0.3); max-height:90vh; display:flex; flex-direction:column;">
            <h3 style="margin-top:0;"><?php esc_html_e('Downloading from Remote Storage...', 'wp-vault'); ?></h3>
            <div class="wpv-progress-bar"
                style="background:#f0f0f0; height:20px; border-radius:10px; overflow:hidden; margin:15px 0;">
                <div id="wpv-download-progress-fill"
                    style="background:#2271b1; height:100%; width:0%; transition:width 0.3s;"></div>
            </div>
            <div id="wpv-download-progress-text" style="font-weight:bold; text-align:center; margin-bottom:10px;">0%</div>
            <div id="wpv-download-progress-message" style="color:#666; font-style:italic; text-align:center;">
                <?php esc_html_e('Initializing download...', 'wp-vault'); ?>
            </div>
            <div id="wpv-download-log-feed"
                style="background:#f8f9fa; border:1px solid #e2e4e7; border-radius:4px; height:300px; overflow-y:auto; padding:10px; font-family:monospace; font-size:12px; margin-top:12px; flex:1;">
                <div style="color:#888;"><?php esc_html_e('Waiting for download logs...', 'wp-vault'); ?></div>
            </div>
            <div id="wpv-download-modal-actions" style="margin-top:20px; text-align:right; display:none;">
                <button class="button button-primary"
                    onclick="location.reload()"><?php esc_html_e('Close & Refresh', 'wp-vault'); ?></button>
            </div>
        </div>
    </div>

    <!-- Logs Modal -->
    <div id="wpv-logs-modal"
        style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999; overflow-y:auto;">
        <div
            style="background:#fff; width:900px; max-width:95vw; max-height:90vh; margin:30px auto; padding:25px; border-radius:5px; box-shadow:0 0 20px rgba(0,0,0,0.3); display:flex; flex-direction:column; position:relative;">
            <div
                style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px; border-bottom:1px solid #e2e4e7; padding-bottom:15px;">
                <h3 style="margin:0; font-size:18px;"><?php esc_html_e('Backup Logs', 'wp-vault'); ?></h3>
                <button class="button" id="wpv-close-logs-modal"><?php esc_html_e('Close', 'wp-vault'); ?></button>
            </div>
            <div style="margin-bottom:15px; display:flex; justify-content:space-between; align-items:center;">
                <span id="wpv-logs-backup-id" style="color:#666; font-size:13px; font-family:monospace;"></span>
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
            // Backup Now button (from Dashboard and Backups tabs)
            $('#wpv-backup-now, #wpv-backup-now-dashboard').on('click', function () {
                var $btn = $(this);
                var backupType = $('input[name="backup_content"]:checked').val() || 'full';

                $('#wpv-progress-modal').show();
                $('#wpv-progress-fill').css('width', '0%');
                $('#wpv-progress-text').text('0%');
                $('#wpv-progress-message').text('<?php esc_html_e('Starting backup...', 'wp-vault'); ?>');
                $('#wpv-log-feed').html('<div style="color:#888;"><?php esc_html_e('Waiting for logs...', 'wp-vault'); ?></div>');
                $('#wpv-modal-actions').hide();

                $.post(ajaxurl, {
                    action: 'wpv_trigger_backup',
                    backup_type: backupType,
                    nonce: wpVault.nonce
                }, function (response) {
                    if (response.success) {
                        var backupId = response.data.backup_id;
                        pollProgress(backupId);
                    } else {
                        alert('<?php esc_html_e('Backup failed to start:', 'wp-vault'); ?> ' + (response.data.error || '<?php esc_html_e('Unknown error', 'wp-vault'); ?>'));
                        $('#wpv-progress-modal').hide();
                    }
                }).fail(function () {
                    alert('<?php esc_html_e('Network error starting backup', 'wp-vault'); ?>');
                    $('#wpv-progress-modal').hide();
                });
            });

            function pollProgress(backupId) {
                var pollInterval = setInterval(function () {
                    $.post(ajaxurl, {
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

                            if (status === 'completed' || status === 'failed') {
                                clearInterval(pollInterval);
                                $('#wpv-modal-actions').show();
                                if (status === 'completed') {
                                    $('#wpv-progress-fill').css('background', '#46b450');
                                    $('#wpv-progress-message').text('<?php esc_html_e('Backup completed successfully!', 'wp-vault'); ?>');
                                    setTimeout(function () {
                                        $('#wpv-progress-modal').hide();
                                        location.reload();
                                    }, 3000);
                                } else {
                                    $('#wpv-progress-fill').css('background', '#dc3232');
                                }
                            }
                        }
                    });
                }, 1000);
            }

            function renderLogs(logs) {
                var $feed = $('#wpv-log-feed');
                if (!logs || logs.length === 0) {
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

            // Restore functionality (from backups table and restores tab)
            var currentBackupFile = null;
            var currentBackupPath = null;

            $(document).on('click', '.wpv-restore-backup-btn', function () {
                var $btn = $(this);
                currentBackupFile = $btn.data('backup-file');
                currentBackupPath = $btn.data('backup-path');
                var backupId = $btn.data('backup-id');
                $('#wpv-restore-options-modal').data('backup-id', backupId);
                $('#wpv-restore-options-modal').data('backup-file', currentBackupFile);
                $('#wpv-restore-options-modal').data('backup-path', currentBackupPath);
                $('input[name="restore_component"]').prop('checked', true);
                $('input[name="restore_option"][value="pre_restore_backup"]').prop('checked', true);
                $('input[name="restore_option"]:not([value="pre_restore_backup"])').prop('checked', false);
                $('#wpv-restore-options-modal').show();
            });

            // Also handle restore from restores tab (when backup is selected from dropdown)
            // This is handled in tab-restores.php inline script

            $('#wpv-cancel-restore-options').on('click', function () {
                $('#wpv-restore-options-modal').hide();
                currentBackupFile = null;
                currentBackupPath = null;
            });

            $('#wpv-confirm-restore-options').on('click', function () {
                var components = [];
                $('input[name="restore_component"]:checked').each(function () {
                    components.push($(this).val());
                });
                if (components.length === 0) {
                    alert('<?php esc_html_e('Please select at least one component to restore.', 'wp-vault'); ?>');
                    return;
                }
                var options = {};
                $('input[name="restore_option"]:checked').each(function () {
                    options[$(this).val()] = true;
                });
                var backupId = $('#wpv-restore-options-modal').data('backup-id');
                var backupFile = $('#wpv-restore-options-modal').data('backup-file') || currentBackupFile;
                var backupPath = $('#wpv-restore-options-modal').data('backup-path') || currentBackupPath;
                var restoreMode = components.length === 1 ? (components[0] === 'database' ? 'database' : 'files') : 'full';

                $('#wpv-restore-options-modal').hide();
                $('#wpv-restore-modal').show();
                $('#wpv-restore-progress-fill').css('width', '0%');
                $('#wpv-restore-progress-text').text('0%');
                $('#wpv-restore-progress-message').text('<?php esc_html_e('Starting restore...', 'wp-vault'); ?>');
                $('#wpv-restore-log-feed').html('<div style="color:#888;"><?php esc_html_e('Waiting for logs...', 'wp-vault'); ?></div>');
                $('#wpv-restore-modal-actions').hide();

                // If backup_file or backup_path are empty, the handler will determine them from backup_id
                $.post(ajaxurl, {
                    action: 'wpv_restore_backup',
                    backup_id: backupId,
                    backup_file: backupFile || '',
                    backup_path: backupPath || '',
                    restore_mode: restoreMode,
                    components: components,
                    restore_options: options,
                    nonce: wpVault.nonce
                }, function (response) {
                    if (response.success) {
                        var restoreId = response.data.restore_id;
                        pollRestoreProgress(restoreId);
                    } else {
                        alert('<?php esc_html_e('Restore failed to start:', 'wp-vault'); ?> ' + (response.data.error || '<?php esc_html_e('Unknown error', 'wp-vault'); ?>'));
                        $('#wpv-restore-modal').hide();
                    }
                }).fail(function () {
                    alert('<?php esc_html_e('Network error starting restore', 'wp-vault'); ?>');
                    $('#wpv-restore-modal').hide();
                });
            });

            function pollRestoreProgress(restoreId) {
                var pollInterval = setInterval(function () {
                    $.post(ajaxurl, {
                        action: 'wpv_get_restore_status',
                        restore_id: restoreId,
                        nonce: wpVault.nonce
                    }, function (response) {
                        if (response.success) {
                            var status = response.data.status;
                            var progress = response.data.progress;
                            var message = response.data.message;
                            var logs = response.data.logs || [];

                            $('#wpv-restore-progress-fill').css('width', progress + '%');
                            $('#wpv-restore-progress-text').text(progress + '%');
                            $('#wpv-restore-progress-message').text(status + (message ? ': ' + message : ''));
                            renderRestoreLogs(logs);

                            if (status === 'completed' || status === 'failed') {
                                clearInterval(pollInterval);
                                $('#wpv-restore-modal-actions').show();
                                if (status === 'completed') {
                                    $('#wpv-restore-progress-fill').css('background', '#46b450');
                                    $('#wpv-restore-progress-message').text('<?php esc_html_e('Restore completed successfully!', 'wp-vault'); ?>');
                                    setTimeout(function () {
                                        $('#wpv-restore-modal').hide();
                                        location.reload();
                                    }, 3000);
                                } else {
                                    $('#wpv-restore-progress-fill').css('background', '#dc3232');
                                }
                            }
                        }
                    });
                }, 1000);
            }

            function renderRestoreLogs(logs) {
                var $feed = $('#wpv-restore-log-feed');
                if (!logs || logs.length === 0) return;
                var html = logs.map(function (log) {
                    var time = log.created_at ? new Date(log.created_at).toLocaleTimeString() : '';
                    var severity = log.severity || 'INFO';
                    return '<div><span style="color:#666;">[' + time + ']</span> <span style="color:' + (severity === 'ERROR' ? '#d63638' : '#2271b1') + ';">' + severity + '</span> ' + $('<div/>').text(log.message).html() + '</div>';
                }).join('');
                $feed.html(html);
                $feed.scrollTop($feed[0].scrollHeight);
            }

            // Expand/collapse backup components
            $('.wpv-expand-toggle').on('click', function () {
                var $toggle = $(this);
                var $row = $toggle.closest('tr');
                var backupId = $row.data('backup-id');
                var $componentsRow = $('.wpv-backup-components[data-backup-id="' + backupId + '"]');
                if ($componentsRow.is(':visible')) {
                    $componentsRow.slideUp();
                    $toggle.removeClass('dashicons-arrow-down').addClass('dashicons-arrow-right');
                } else {
                    $componentsRow.slideDown();
                    $toggle.removeClass('dashicons-arrow-right').addClass('dashicons-arrow-down');
                }
            });

            // Download backup
            $('.wpv-download-backup-btn').on('click', function () {
                var backupId = $(this).data('backup-id');
                window.location.href = wpVault.ajax_url + '?action=wpv_download_backup&backup_id=' + encodeURIComponent(backupId) + '&nonce=' + wpVault.nonce;
            });

            // Delete backup - show confirmation modal
            var pendingDeleteBackupId = null;
            $('.wpv-delete-backup-btn').on('click', function () {
                var $btn = $(this);
                var backupId = $btn.data('backup-id');
                pendingDeleteBackupId = backupId;

                // Reset modal state
                $('#wpv-delete-confirm-input').val('');
                $('#wpv-confirm-delete-backup').prop('disabled', true);
                $('#wpv-delete-backup-modal').show();
            });

            // Handle delete confirmation input
            $('#wpv-delete-confirm-input').on('input', function () {
                var inputValue = $(this).val().trim();
                $('#wpv-confirm-delete-backup').prop('disabled', inputValue !== 'DELETE');
            });

            // Handle Enter key in confirmation input
            $('#wpv-delete-confirm-input').on('keypress', function (e) {
                if (e.which === 13 && $(this).val().trim() === 'DELETE') {
                    $('#wpv-confirm-delete-backup').click();
                }
            });

            // Cancel delete
            $('#wpv-cancel-delete-backup, #wpv-delete-backup-modal').on('click', function (e) {
                if (e.target === this || $(e.target).is('#wpv-cancel-delete-backup')) {
                    $('#wpv-delete-backup-modal').hide();
                    $('#wpv-delete-confirm-input').val('');
                    pendingDeleteBackupId = null;
                }
            });

            // Prevent modal from closing when clicking inside
            $('#wpv-delete-backup-modal > div').on('click', function (e) {
                e.stopPropagation();
            });

            // Confirm delete
            $('#wpv-confirm-delete-backup').on('click', function () {
                if (!pendingDeleteBackupId) {
                    return;
                }

                var backupId = pendingDeleteBackupId;
                var $btn = $(this);
                $btn.prop('disabled', true).text('<?php esc_html_e('Deleting...', 'wp-vault'); ?>');

                $.post(ajaxurl, {
                    action: 'wpv_delete_backup',
                    backup_id: backupId,
                    nonce: wpVault.nonce
                }, function (response) {
                    $('#wpv-delete-backup-modal').hide();
                    $('#wpv-delete-confirm-input').val('');
                    $btn.prop('disabled', false).text('<?php esc_html_e('Delete Backup', 'wp-vault'); ?>');
                    pendingDeleteBackupId = null;

                    if (response.success) {
                        // Update actions based on whether local files still exist
                        updateBackupActions(backupId, response.data.has_local_files !== false);

                        // Show success message
                        if (response.data.deleted_count > 0) {
                            alert(response.data.message || '<?php esc_html_e('Files deleted successfully', 'wp-vault'); ?>');
                        } else if (response.data.cloud_only) {
                            alert(response.data.message || '<?php esc_html_e('No local backup files found. This backup may be stored in cloud storage only.', 'wp-vault'); ?>');
                        }
                    } else {
                        alert('<?php esc_html_e('Failed to delete backup:', 'wp-vault'); ?> ' + (response.data.error || '<?php esc_html_e('Unknown error', 'wp-vault'); ?>'));
                    }
                }).fail(function () {
                    $('#wpv-delete-backup-modal').hide();
                    $('#wpv-delete-confirm-input').val('');
                    $btn.prop('disabled', false).text('<?php esc_html_e('Delete Backup', 'wp-vault'); ?>');
                    pendingDeleteBackupId = null;
                    alert('<?php esc_html_e('Failed to delete backup. Please try again.', 'wp-vault'); ?>');
                });
            });

            // Download from Remote (using event delegation for dynamically added buttons)
            $(document).on('click', '.wpv-download-from-remote-btn', function () {
                var $btn = $(this);
                var backupId = $btn.data('backup-id');

                // Show download modal
                $('#wpv-download-modal').show();
                $('#wpv-download-progress-fill').css('width', '0%');
                $('#wpv-download-progress-text').text('0%');
                $('#wpv-download-progress-message').text('<?php esc_html_e('Initializing download...', 'wp-vault'); ?>');
                $('#wpv-download-log-feed').html('<div style="color:#888;"><?php esc_html_e('Starting download...', 'wp-vault'); ?></div>');
                $('#wpv-download-modal-actions').hide();

                // Start progress polling immediately
                pollDownloadProgress(backupId);

                // Start download
                $.post(ajaxurl, {
                    action: 'wpv_download_backup_from_remote',
                    backup_id: backupId,
                    nonce: wpVault.nonce
                }, function (response) {
                    if (!response.success) {
                        var errorMsg = response.data.error || '<?php esc_html_e('Failed to start download', 'wp-vault'); ?>';
                        $('#wpv-download-progress-fill').css('background', '#dc3232');
                        $('#wpv-download-progress-message').text(errorMsg);
                        $('#wpv-download-modal-actions').show();
                        addDownloadLog('ERROR', errorMsg);
                    }
                }).fail(function () {
                    $('#wpv-download-progress-fill').css('background', '#dc3232');
                    $('#wpv-download-progress-message').text('<?php esc_html_e('Network error starting download', 'wp-vault'); ?>');
                    $('#wpv-download-modal-actions').show();
                    addDownloadLog('ERROR', '<?php esc_html_e('Network error', 'wp-vault'); ?>');
                });
            });

            function pollDownloadProgress(backupId) {
                var pollInterval = setInterval(function () {
                    $.post(ajaxurl, {
                        action: 'wpv_get_download_status',
                        backup_id: backupId,
                        nonce: wpVault.nonce
                    }, function (response) {
                        if (response && response.success) {
                            var status = response.data.status || 'running';
                            var progress = parseInt(response.data.progress) || 0;
                            var message = response.data.message || 'Processing...';
                            var logs = response.data.logs || [];

                            $('#wpv-download-progress-fill').css('width', progress + '%');
                            $('#wpv-download-progress-text').text(progress + '%');
                            if (message) {
                                $('#wpv-download-progress-message').text(message);
                            }
                            if (logs && logs.length > 0) {
                                renderDownloadLogs(logs);
                            }

                            if (status === 'completed' || status === 'failed' || status === 'error') {
                                clearInterval(pollInterval);
                                $('#wpv-download-modal-actions').show();
                                if (status === 'completed') {
                                    $('#wpv-download-progress-fill').css('background', '#46b450');
                                    $('#wpv-download-progress-message').text('<?php esc_html_e('Download completed successfully!', 'wp-vault'); ?>');
                                    setTimeout(function () {
                                        try {
                                            $('#wpv-download-modal').hide();
                                        } catch (e) { }
                                        window.location.reload();
                                    }, 1000);
                                } else {
                                    $('#wpv-download-progress-fill').css('background', '#dc3232');
                                    $('#wpv-download-progress-message').text(message || '<?php esc_html_e('Download failed', 'wp-vault'); ?>');
                                }
                            }
                        } else {
                            console.error('[WP Vault] Download status error:', response);
                        }
                    }).fail(function (xhr, status, error) {
                        console.error('[WP Vault] AJAX request failed:', status, error);
                    });
                }, 1000);
            }

            function renderDownloadLogs(logs) {
                var $feed = $('#wpv-download-log-feed');
                if (!logs || logs.length === 0) return;

                // Support both parsed logs and simple messages
                var lastLogText = $feed.find('div:last').text().trim();
                var startIndex = 0;

                // If it's the first real log update, clear the "Starting download..." placeholder
                if ($feed.find('div').length === 1 && $feed.find('div').css('color') === 'rgb(136, 136, 136)') {
                    $feed.empty();
                    startIndex = 0;
                } else if (lastLogText) {
                    // Find the last message that was already rendered
                    for (var i = logs.length - 1; i >= 0; i--) {
                        var timeStr = '';
                        if (logs[i].created_at) {
                            try {
                                timeStr = new Date(logs[i].created_at).toLocaleTimeString();
                            } catch (e) {
                                timeStr = logs[i].created_at;
                            }
                        }
                        var severity = logs[i].severity || 'INFO';
                        var message = logs[i].message || '';
                        var fullText = '[' + timeStr + '] ' + severity + ' ' + message;

                        if (fullText.trim() === lastLogText) {
                            startIndex = i + 1;
                            break;
                        }
                    }
                }

                if (startIndex >= logs.length) return;

                // Append only new logs
                for (var j = startIndex; j < logs.length; j++) {
                    var log = logs[j];
                    var time = '';
                    if (log.created_at) {
                        try {
                            time = new Date(log.created_at).toLocaleTimeString();
                        } catch (e) {
                            time = log.created_at;
                        }
                    }
                    var severity = log.severity || 'INFO';
                    var color = severity === 'ERROR' ? '#d63638' : (severity === 'WARNING' ? '#dba617' : '#2271b1');
                    var html = '<div><span style="color:#666;">[' + time + ']</span> <span style="color:' + color + ';">' + severity + '</span> ' + $('<div/>').text(log.message).html() + '</div>';
                    $feed.append(html);
                }

                // Scroll to bottom
                $feed.scrollTop($feed[0].scrollHeight);
            }

            function addDownloadLog(severity, message) {
                var $feed = $('#wpv-download-log-feed');
                var time = new Date().toLocaleTimeString();
                var color = severity === 'ERROR' ? '#d63638' : (severity === 'WARNING' ? '#dba617' : '#2271b1');
                var html = '<div><span style="color:#666;">[' + time + ']</span> <span style="color:' + color + ';">' + severity + '</span> ' + $('<div/>').text(message).html() + '</div>';
                $feed.append(html);
                $feed.scrollTop($feed[0].scrollHeight);
            }

            // Remove from DB - use event delegation for dynamically created buttons
            $(document).on('click', '.wpv-remove-from-db-btn', function () {
                var $btn = $(this);
                var backupId = $btn.data('backup-id');

                if (!confirm('<?php esc_html_e('Remove this backup record from the database? This will remove it from the list, but remote backup files will remain in cloud storage.', 'wp-vault'); ?>')) {
                    return;
                }

                $btn.prop('disabled', true).text('<?php esc_html_e('Removing...', 'wp-vault'); ?>');

                $.post(ajaxurl, {
                    action: 'wpv_remove_backup_from_db',
                    backup_id: backupId,
                    nonce: wpVault.nonce
                }, function (response) {
                    if (response.success) {
                        // Remove from UI
                        $('.wpv-backup-row[data-backup-id="' + backupId + '"], .wpv-backup-components[data-backup-id="' + backupId + '"]').fadeOut(function () {
                            $(this).remove();
                        });
                    } else {
                        $btn.prop('disabled', false).text('<?php esc_html_e('Remove from DB', 'wp-vault'); ?>');
                        alert('<?php esc_html_e('Failed to remove backup:', 'wp-vault'); ?> ' + (response.data.error || '<?php esc_html_e('Unknown error', 'wp-vault'); ?>'));
                    }
                }).fail(function (xhr, status, error) {
                    $btn.prop('disabled', false).text('<?php esc_html_e('Remove from DB', 'wp-vault'); ?>');
                    alert('<?php esc_html_e('Failed to remove backup. Please try again.', 'wp-vault'); ?>');
                });
            });

            // Function to update backup actions based on local file status
            function updateBackupActions(backupId, hasLocalFiles) {
                var $actionsCell = $('.wpv-backup-actions[data-backup-id="' + backupId + '"]');
                if ($actionsCell.length === 0) return;

                // Get restore button (keep it)
                var $restoreBtn = $actionsCell.find('.wpv-restore-backup-btn');
                var restoreBtnHtml = $restoreBtn[0].outerHTML;

                if (hasLocalFiles) {
                    // Show: Restore, Download, Delete File
                    $actionsCell.html(restoreBtnHtml +
                        '<button class="button wpv-download-backup-btn" data-backup-id="' + backupId + '" style="margin-left: 5px;"><?php esc_html_e('Download', 'wp-vault'); ?></button>' +
                        '<button class="button wpv-delete-backup-btn" data-backup-id="' + backupId + '" style="margin-left: 5px;"><?php esc_html_e('Delete File', 'wp-vault'); ?></button>'
                    );

                    // Re-attach event handlers
                    $actionsCell.find('.wpv-download-backup-btn').on('click', function () {
                        var bid = $(this).data('backup-id');
                        window.location.href = wpVault.ajax_url + '?action=wpv_download_backup&backup_id=' + encodeURIComponent(bid) + '&nonce=' + wpVault.nonce;
                    });
                    $actionsCell.find('.wpv-delete-backup-btn').on('click', function () {
                        var $b = $(this);
                        var bid = $b.data('backup-id');
                        pendingDeleteBackupId = bid;
                        $('#wpv-delete-confirm-input').val('');
                        $('#wpv-confirm-delete-backup').prop('disabled', true);
                        $('#wpv-delete-backup-modal').show();
                    });
                } else {
                    // Show: Download from Remote (primary), Remove from DB
                    $actionsCell.html(
                        '<button class="button button-primary wpv-download-from-remote-btn" data-backup-id="' + backupId + '"><?php esc_html_e('Download from Remote', 'wp-vault'); ?></button>' +
                        '<button class="button wpv-remove-from-db-btn" data-backup-id="' + backupId + '" style="margin-left: 5px;"><?php esc_html_e('Remove from DB', 'wp-vault'); ?></button>'
                    );

                    // Re-attach event handlers
                    $actionsCell.find('.wpv-download-from-remote-btn').on('click', function () {
                        var $b = $(this);
                        var bid = $b.data('backup-id');
                        if (!confirm('<?php esc_html_e('Download backup files from remote storage? This may take a while depending on backup size.', 'wp-vault'); ?>')) {
                            return;
                        }
                        $b.prop('disabled', true).text('<?php esc_html_e('Downloading...', 'wp-vault'); ?>');
                        $.post(ajaxurl, {
                            action: 'wpv_download_backup_from_remote',
                            backup_id: bid,
                            nonce: wpVault.nonce
                        }, function (response) {
                            $b.prop('disabled', false).text('<?php esc_html_e('Download from Remote', 'wp-vault'); ?>');
                            if (response.success) {
                                alert(response.data.message || '<?php esc_html_e('Files downloaded successfully', 'wp-vault'); ?>');
                                updateBackupActions(bid, true);
                                location.reload();
                            } else {
                                alert(response.data.error || '<?php esc_html_e('Failed to download files', 'wp-vault'); ?>');
                            }
                        }).fail(function () {
                            $b.prop('disabled', false).text('<?php esc_html_e('Download from Remote', 'wp-vault'); ?>');
                            alert('<?php esc_html_e('Failed to download backup. Please try again.', 'wp-vault'); ?>');
                        });
                    });
                    // Note: Remove from DB handler is already attached via event delegation above
                    // No need to re-attach here since we're using $(document).on()
                }
            }

            // Logs filtering
            $('#wpv-log-filter, #wpv-log-severity').on('change', function () {
                var filterType = $('#wpv-log-filter').val();
                var filterSeverity = $('#wpv-log-severity').val();

                $('.wpv-log-row').each(function () {
                    var $row = $(this);
                    var severity = $row.data('severity');
                    var jobId = $row.data('job-id');
                    var show = true;

                    // Filter by type (backup/restore)
                    if (filterType !== 'all') {
                        // This would need job type info - for now show all
                    }

                    // Filter by severity
                    if (filterSeverity !== 'all') {
                        if (filterSeverity === 'ERROR' && severity !== 'ERROR') {
                            show = false;
                        } else if (filterSeverity === 'WARNING' && severity !== 'ERROR' && severity !== 'WARNING') {
                            show = false;
                        }
                    }

                    if (show) {
                        $row.show();
                    } else {
                        $row.hide();
                    }
                });
            });

            // Refresh logs
            $('#wpv-refresh-logs').on('click', function () {
                location.reload();
            });

            // View job logs
            $('.wpv-view-job-logs').on('click', function () {
                var jobId = $(this).data('job-id');
                // Open logs modal with job-specific logs
                $('#wpv-logs-modal').show();
                $('#wpv-logs-backup-id').text('Job ID: ' + jobId);
                $('#wpv-logs-content').html('<div style="color:#888;"><?php esc_html_e('Loading logs...', 'wp-vault'); ?></div>');

                // Fetch job logs via AJAX
                $.post(ajaxurl, {
                    action: 'wpv_get_job_logs',
                    job_id: jobId,
                    nonce: wpVault.nonce
                }, function (response) {
                    if (response.success && response.data.logs) {
                        renderLogsInModal(response.data.logs);
                    } else {
                        $('#wpv-logs-content').html('<div style="color:#d63638;"><?php esc_html_e('No logs found for this job.', 'wp-vault'); ?></div>');
                    }
                }).fail(function () {
                    $('#wpv-logs-content').html('<div style="color:#d63638;"><?php esc_html_e('Error loading logs.', 'wp-vault'); ?></div>');
                });
            });

            // Cleanup temp files (from Settings tab)
            $('#cleanup-temp-files').on('click', function () {
                var $btn = $(this);
                var $result = $('#cleanup-result');
                $btn.prop('disabled', true).text('<?php esc_html_e('Cleaning...', 'wp-vault'); ?>');
                $result.html('');
                $.post(ajaxurl, {
                    action: 'wpv_cleanup_temp_files',
                    nonce: wpVault.nonce
                }, function (response) {
                    if (response.success) {
                        $result.html('<span style="color:green">✓ ' + response.data.message + '</span>');
                        setTimeout(function () {
                            location.reload();
                        }, 1500);
                    } else {
                        $result.html('<span style="color:red">✗ ' + (response.data.error || '<?php esc_html_e('Cleanup failed', 'wp-vault'); ?>') + '</span>');
                    }
                }).always(function () {
                    $btn.prop('disabled', false).text('<?php esc_html_e('Clean Up Old Temp Files', 'wp-vault'); ?>');
                });
            });

            // Logs modal
            var currentLogFile = null;
            $(document).on('click', '.wpv-show-logs', function () {
                var backupId = $(this).data('backup-id');
                $('#wpv-logs-modal').show();
                $('#wpv-logs-backup-id').text('Backup ID: ' + backupId);
                $('#wpv-logs-content').html('<div style="color:#888;"><?php esc_html_e('Loading logs...', 'wp-vault'); ?></div>');
                $('#wpv-download-logs-btn').hide();
                currentLogFile = null;

                $.post(ajaxurl, {
                    action: 'wpv_get_backup_status',
                    backup_id: backupId,
                    nonce: wpVault.nonce
                }, function (response) {
                    if (response.success && response.data.log_file_path) {
                        currentLogFile = response.data.log_file_path;
                        $('#wpv-download-logs-btn').show();
                        loadLogs(currentLogFile);
                    } else if (response.success && response.data.logs && response.data.logs.length > 0) {
                        renderLogsInModal(response.data.logs);
                    } else {
                        $('#wpv-logs-content').html('<div style="color:#d63638;"><?php esc_html_e('No logs found for this backup.', 'wp-vault'); ?></div>');
                    }
                }).fail(function () {
                    $('#wpv-logs-content').html('<div style="color:#d63638;"><?php esc_html_e('Error loading logs.', 'wp-vault'); ?></div>');
                });
            });

            $('#wpv-close-logs-modal, #wpv-logs-modal').on('click', function (e) {
                if (e.target === this || $(e.target).is('#wpv-close-logs-modal')) {
                    $('#wpv-logs-modal').hide();
                    currentLogFile = null;
                }
            });

            $('#wpv-logs-modal > div').on('click', function (e) {
                e.stopPropagation();
            });

            $('#wpv-download-logs-btn').on('click', function () {
                if (currentLogFile) {
                    window.location.href = wpVault.ajax_url + '?action=wpv_download_log&log_file=' + encodeURIComponent(currentLogFile) + '&nonce=' + wpVault.nonce;
                }
            });

            function loadLogs(logFilePath) {
                $.post(ajaxurl, {
                    action: 'wpv_read_log',
                    log_file: logFilePath,
                    lines: -200,
                    nonce: wpVault.nonce
                }, function (response) {
                    if (response.success && response.data.content) {
                        var logLines = response.data.content.split('\n');
                        var html = '';
                        logLines.forEach(function (line) {
                            if (line.trim()) {
                                var match = line.match(/^\[([^\]]+)\]\[([^\]]+)\]\s*(.+)$/);
                                if (match) {
                                    var timestamp = match[1];
                                    var level = match[2];
                                    var message = $('<div/>').text(match[3]).html();
                                    var levelColor = level === 'ERROR' ? '#f48771' : (level === 'WARNING' ? '#dcdcaa' : '#4ec9b0');
                                    html += '<div style="margin-bottom:2px;"><span style="color:#858585;">[' + timestamp + ']</span> <span style="color:' + levelColor + '; font-weight:bold;">[' + level + ']</span> <span style="color:#d4d4d4;">' + message + '</span></div>';
                                } else {
                                    html += '<div style="margin-bottom:2px; color:#d4d4d4;">' + $('<div/>').text(line).html() + '</div>';
                                }
                            }
                        });
                        $('#wpv-logs-content').html(html || '<div style="color:#888;"><?php esc_html_e('No log content found.', 'wp-vault'); ?></div>');
                        var $content = $('#wpv-logs-content');
                        $content.scrollTop($content[0].scrollHeight);
                    } else {
                        $('#wpv-logs-content').html('<div style="color:#d63638;"><?php esc_html_e('Error reading log file.', 'wp-vault'); ?></div>');
                    }
                }).fail(function () {
                    $('#wpv-logs-content').html('<div style="color:#d63638;"><?php esc_html_e('Error loading logs.', 'wp-vault'); ?></div>');
                });
            }

            function renderLogsInModal(logs) {
                var html = '';
                logs.forEach(function (log) {
                    var time = log.created_at ? new Date(log.created_at).toLocaleTimeString() : '';
                    var severity = log.severity || 'INFO';
                    var levelColor = severity === 'ERROR' ? '#f48771' : (severity === 'WARNING' ? '#dcdcaa' : '#4ec9b0');
                    html += '<div style="margin-bottom:2px;"><span style="color:#858585;">[' + time + ']</span> <span style="color:' + levelColor + '; font-weight:bold;">[' + severity + ']</span> <span style="color:#d4d4d4;">' + $('<div/>').text(log.message).html() + '</span></div>';
                });
                $('#wpv-logs-content').html(html || '<div style="color:#888;"><?php esc_html_e('No logs available.', 'wp-vault'); ?></div>');
                var $content = $('#wpv-logs-content');
                $content.scrollTop($content[0].scrollHeight);
            }
        });
    </script>
    <?php
}

// Call the function to display the dashboard
wpvault_display_dashboard_page();
?>