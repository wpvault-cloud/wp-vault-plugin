<?php
/**
 * WP Vault Backups Page
 *
 * @package WP_Vault
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

// Get local backup files (grouped by backup_id)
$backup_dir = WP_CONTENT_DIR . '/wp-vault-backups/';
$backups = array();
$backups_by_id = array();

if (is_dir($backup_dir)) {
    // First, read manifest files to get backup information
    $manifest_files = glob($backup_dir . 'backup-*-manifest.json');
    foreach ($manifest_files as $manifest_file) {
        $manifest_data = json_decode(file_get_contents($manifest_file), true);
        if ($manifest_data && isset($manifest_data['backup_id'])) {
            $backup_id = $manifest_data['backup_id'];

            // Verify all files exist and calculate actual total size
            $actual_files = array();
            $actual_total_size = 0;
            if (isset($manifest_data['files'])) {
                foreach ($manifest_data['files'] as $file) {
                    $file_path = $backup_dir . $file['filename'];
                    if (file_exists($file_path)) {
                        $file_size = filesize($file_path);
                        $actual_files[] = array(
                            'filename' => $file['filename'],
                            'path' => $file_path,
                            'size' => $file_size,
                        );
                        $actual_total_size += $file_size;
                    }
                }
            }

            $backups_by_id[$backup_id] = array(
                'backup_id' => $backup_id,
                'backup_type' => isset($manifest_data['backup_type']) ? $manifest_data['backup_type'] : 'full',
                'compression_mode' => isset($manifest_data['compression_mode']) ? $manifest_data['compression_mode'] : 'fast',
                'total_size' => $actual_total_size > 0 ? $actual_total_size : (isset($manifest_data['total_size']) ? $manifest_data['total_size'] : 0),
                'created_at' => isset($manifest_data['created_at']) ? $manifest_data['created_at'] : date('Y-m-d H:i:s', filemtime($manifest_file)),
                'date' => filemtime($manifest_file),
                'components' => isset($manifest_data['components']) ? $manifest_data['components'] : array(),
                'files' => $actual_files,
                'manifest_file' => basename($manifest_file),
            );
        }
    }

    // Also check for legacy single-file backups (without manifest)
    $legacy_files = glob($backup_dir . 'backup-*.tar.gz');
    foreach ($legacy_files as $file) {
        $filename = basename($file);
        // Skip if this is a component file (has component prefix)
        if (preg_match('/^(database|themes|plugins|uploads|wp-content)-/', $filename)) {
            continue; // Already handled by manifest
        }

        // Extract backup_id from filename
        if (preg_match('/backup-([a-zA-Z0-9_-]+)-/', $filename, $matches)) {
            $backup_id = $matches[1];
            if (!isset($backups_by_id[$backup_id])) {
                $backups_by_id[$backup_id] = array(
                    'backup_id' => $backup_id,
                    'backup_type' => 'full',
                    'compression_mode' => 'fast',
                    'total_size' => filesize($file),
                    'created_at' => date('Y-m-d H:i:s', filemtime($file)),
                    'date' => filemtime($file),
                    'components' => array(),
                    'files' => array(
                        array(
                            'filename' => $filename,
                            'path' => $file,
                            'size' => filesize($file),
                        )
                    ),
                    'manifest_file' => null,
                );
            }
        }
    }

    // Convert to array and sort by date
    $backups = array_values($backups_by_id);
    usort($backups, function ($a, $b) {
        return $b['date'] - $a['date'];
    });
}
?>

<div class="wrap">
    <h1><?php _e('WP Vault Backups', 'wp-vault'); ?></h1>

    <?php if (empty($backups)): ?>
        <div class="notice notice-info">
            <p><?php _e('No local backups found. Create a backup from the Dashboard to get started.', 'wp-vault'); ?></p>
        </div>
    <?php else: ?>
        <p><?php printf(__('Found %d local backup(s) stored in %s', 'wp-vault'), count($backups), '<code>wp-content/wp-vault-backups/</code>'); ?>
        </p>

        <table class="wp-list-table widefat fixed striped" id="wpv-backups-table">
            <thead>
                <tr>
                    <th style="width:30px;"></th>
                    <th><?php _e('Backup', 'wp-vault'); ?></th>
                    <th><?php _e('Size', 'wp-vault'); ?></th>
                    <th><?php _e('Components', 'wp-vault'); ?></th>
                    <th><?php _e('Date', 'wp-vault'); ?></th>
                    <th><?php _e('Actions', 'wp-vault'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($backups as $backup):
                    $backup_id = $backup['backup_id'];
                    $has_components = !empty($backup['components']) || count($backup['files']) > 1;
                    $backup_name = 'Backup ' . substr($backup_id, 0, 8) . '...';
                    $backup_date = strtotime($backup['created_at']);
                    $total_size = $backup['total_size'];
                    if ($total_size === 0 && !empty($backup['files'])) {
                        // Calculate total from files
                        foreach ($backup['files'] as $file) {
                            $total_size += isset($file['size']) ? $file['size'] : (file_exists($backup_dir . $file['filename']) ? filesize($backup_dir . $file['filename']) : 0);
                        }
                    }
                    ?>
                    <tr class="wpv-backup-row" data-backup-id="<?php echo esc_attr($backup_id); ?>">
                        <td>
                            <?php if ($has_components): ?>
                                <span class="wpv-expand-toggle dashicons dashicons-arrow-right" style="cursor:pointer; color:#666;"
                                    title="<?php _e('Click to expand/collapse', 'wp-vault'); ?>"></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <strong><?php echo esc_html($backup_name); ?></strong>
                            <br>
                            <small style="color:#666;">ID: <?php echo esc_html($backup_id); ?></small>
                        </td>
                        <td><strong><?php echo size_format($total_size); ?></strong></td>
                        <td>
                            <?php
                            $component_count = count($backup['components']);
                            if ($component_count > 0) {
                                echo $component_count . ' ' . _n('component', 'components', $component_count, 'wp-vault');
                            } else {
                                echo '1 ' . __('file', 'wp-vault');
                            }
                            ?>
                        </td>
                        <td><?php echo esc_html(date('M j, Y g:i a', $backup_date)); ?></td>
                        <td>
                            <?php
                            // Use first file for restore (or manifest if available)
                            $primary_file = !empty($backup['files']) ? $backup['files'][0]['filename'] : 'backup-' . $backup_id . '.tar.gz';
                            $primary_path = $backup_dir . $primary_file;
                            if (!file_exists($primary_path) && !empty($backup['files'])) {
                                // Try to find any existing file
                                foreach ($backup['files'] as $file) {
                                    if (file_exists($backup_dir . $file['filename'])) {
                                        $primary_file = $file['filename'];
                                        $primary_path = $backup_dir . $file['filename'];
                                        break;
                                    }
                                }
                            }
                            ?>
                            <button class="button button-primary wpv-restore-backup-btn"
                                data-backup-id="<?php echo esc_attr($backup_id); ?>"
                                data-backup-file="<?php echo esc_attr($primary_file); ?>"
                                data-backup-path="<?php echo esc_attr($primary_path); ?>">
                                <?php _e('Restore', 'wp-vault'); ?>
                            </button>
                            <button class="button wpv-download-backup-btn" data-backup-id="<?php echo esc_attr($backup_id); ?>"
                                style="margin-left: 5px;">
                                <?php _e('Download', 'wp-vault'); ?>
                            </button>
                            <button class="button wpv-delete-backup-btn" data-backup-id="<?php echo esc_attr($backup_id); ?>"
                                style="margin-left: 5px;">
                                <?php _e('Delete', 'wp-vault'); ?>
                            </button>
                        </td>
                    </tr>
                    <?php if ($has_components): ?>
                        <tr class="wpv-backup-components" data-backup-id="<?php echo esc_attr($backup_id); ?>"
                            style="display:none;">
                            <td></td>
                            <td colspan="5">
                                <table class="wp-list-table widefat" style="margin:10px 0; background:#f9f9f9;">
                                    <thead>
                                        <tr>
                                            <th style="padding:8px;"><?php _e('Component', 'wp-vault'); ?></th>
                                            <th style="padding:8px;"><?php _e('File', 'wp-vault'); ?></th>
                                            <th style="padding:8px;"><?php _e('Size', 'wp-vault'); ?></th>
                                            <th style="padding:8px;"><?php _e('Actions', 'wp-vault'); ?></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        $component_map = array(
                                            'database' => 'Database',
                                            'themes' => 'Themes',
                                            'plugins' => 'Plugins',
                                            'uploads' => 'Uploads',
                                            'wp-content' => 'WP-Content',
                                        );

                                        // Show components from manifest
                                        if (!empty($backup['components'])) {
                                            foreach ($backup['components'] as $component) {
                                                $component_name = isset($component['name']) ? $component['name'] : '';
                                                $component_label = isset($component_map[$component_name]) ? $component_map[$component_name] : ucfirst($component_name);
                                                $component_archives = isset($component['archives']) ? $component['archives'] : array();

                                                foreach ($component_archives as $archive_path) {
                                                    $archive_filename = basename($archive_path);
                                                    // Find matching file in backup files
                                                    $file_info = null;
                                                    foreach ($backup['files'] as $file) {
                                                        if (strpos($file['filename'], $component_name . '-') === 0) {
                                                            $file_info = $file;
                                                            break;
                                                        }
                                                    }

                                                    if (!$file_info) {
                                                        // Try to find by checking if file exists
                                                        $possible_path = $backup_dir . $archive_filename;
                                                        if (file_exists($possible_path)) {
                                                            $file_info = array(
                                                                'filename' => $archive_filename,
                                                                'path' => $possible_path,
                                                                'size' => filesize($possible_path),
                                                            );
                                                        }
                                                    }

                                                    if ($file_info):
                                                        ?>
                                                        <tr>
                                                            <td style="padding:8px;"><strong><?php echo esc_html($component_label); ?></strong></td>
                                                            <td style="padding:8px;"><code
                                                                    style="font-size:11px;"><?php echo esc_html($file_info['filename']); ?></code>
                                                            </td>
                                                            <td style="padding:8px;"><?php echo size_format($file_info['size']); ?></td>
                                                            <td style="padding:8px;">
                                                                <a href="<?php echo admin_url('admin-ajax.php?action=wpv_download_backup_file&file=' . urlencode($file_info['filename']) . '&nonce=' . wp_create_nonce('wp-vault')); ?>"
                                                                    class="button button-small">
                                                                    <?php _e('Download', 'wp-vault'); ?>
                                                                </a>
                                                            </td>
                                                        </tr>
                                                        <?php
                                                    endif;
                                                }
                                            }
                                        } else {
                                            // Fallback: show files directly
                                            foreach ($backup['files'] as $file):
                                                $file_path = isset($file['path']) ? $file['path'] : $backup_dir . $file['filename'];
                                                ?>
                                                <tr>
                                                    <td style="padding:8px;"><?php _e('File', 'wp-vault'); ?></td>
                                                    <td style="padding:8px;"><code
                                                            style="font-size:11px;"><?php echo esc_html($file['filename']); ?></code></td>
                                                    <td style="padding:8px;"><?php echo size_format($file['size']); ?></td>
                                                    <td style="padding:8px;">
                                                        <a href="<?php echo admin_url('admin-ajax.php?action=wpv_download_backup_file&file=' . urlencode($file['filename']) . '&nonce=' . wp_create_nonce('wp-vault')); ?>"
                                                            class="button button-small">
                                                            <?php _e('Download', 'wp-vault'); ?>
                                                        </a>
                                                    </td>
                                                </tr>
                                                <?php
                                            endforeach;
                                        }
                                        ?>
                                    </tbody>
                                </table>
                            </td>
                        </tr>
                    <?php endif; ?>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <!-- Restore Options Modal -->
    <div id="wpv-restore-options-modal"
        style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999;">
        <div
            style="background:#fff; width:600px; max-height:90vh; overflow-y:auto; margin:50px auto; padding:20px; border-radius:5px; box-shadow:0 0 10px rgba(0,0,0,0.3);">
            <h3 style="margin-top:0;"><?php _e('Restore Options', 'wp-vault'); ?></h3>
            <p style="color:#666; margin-top:5px;">
                <?php _e('Select what you want to restore and configure restore options.', 'wp-vault'); ?>
            </p>

            <div style="margin:20px 0;">
                <h4 style="margin-bottom:10px;"><?php _e('Components to Restore', 'wp-vault'); ?></h4>
                <div style="margin-left:10px;">
                    <label style="display:block; margin:8px 0;">
                        <input type="checkbox" name="restore_component" value="database" checked
                            style="margin-right:8px;">
                        <strong><?php _e('Database', 'wp-vault'); ?></strong>
                        <span style="color:#666; font-size:12px;"> -
                            <?php _e('Restore all database tables', 'wp-vault'); ?></span>
                    </label>
                    <label style="display:block; margin:8px 0;">
                        <input type="checkbox" name="restore_component" value="themes" checked
                            style="margin-right:8px;">
                        <strong><?php _e('Themes', 'wp-vault'); ?></strong>
                        <span style="color:#666; font-size:12px;"> -
                            <?php _e('Restore theme files', 'wp-vault'); ?></span>
                    </label>
                    <label style="display:block; margin:8px 0;">
                        <input type="checkbox" name="restore_component" value="plugins" checked
                            style="margin-right:8px;">
                        <strong><?php _e('Plugins', 'wp-vault'); ?></strong>
                        <span style="color:#666; font-size:12px;"> -
                            <?php _e('Restore plugin files', 'wp-vault'); ?></span>
                    </label>
                    <label style="display:block; margin:8px 0;">
                        <input type="checkbox" name="restore_component" value="uploads" checked
                            style="margin-right:8px;">
                        <strong><?php _e('Uploads', 'wp-vault'); ?></strong>
                        <span style="color:#666; font-size:12px;"> -
                            <?php _e('Restore media and uploads', 'wp-vault'); ?></span>
                    </label>
                    <label style="display:block; margin:8px 0;">
                        <input type="checkbox" name="restore_component" value="wp-content" checked
                            style="margin-right:8px;">
                        <strong><?php _e('WP-Content (Other)', 'wp-vault'); ?></strong>
                        <span style="color:#666; font-size:12px;"> -
                            <?php _e('Restore other wp-content files', 'wp-vault'); ?></span>
                    </label>
                </div>
            </div>

            <div style="margin:20px 0; padding-top:20px; border-top:1px solid #e2e4e7;">
                <h4 style="margin-bottom:10px;"><?php _e('Advanced Options', 'wp-vault'); ?></h4>
                <div style="margin-left:10px;">
                    <label style="display:block; margin:8px 0;">
                        <input type="checkbox" name="restore_option" value="reset_directories"
                            style="margin-right:8px;">
                        <strong><?php _e('Reset Directories', 'wp-vault'); ?></strong>
                        <span style="color:#666; font-size:12px;"> -
                            <?php _e('Delete existing directories before restore (clean install)', 'wp-vault'); ?></span>
                    </label>
                    <label style="display:block; margin:8px 0;">
                        <input type="checkbox" name="restore_option" value="replace_urls" style="margin-right:8px;">
                        <strong><?php _e('Replace URLs', 'wp-vault'); ?></strong>
                        <span style="color:#666; font-size:12px;"> -
                            <?php _e('Replace old URLs with current site URL (for migration)', 'wp-vault'); ?></span>
                    </label>
                    <label style="display:block; margin:8px 0;">
                        <input type="checkbox" name="restore_option" value="deactivate_plugins"
                            style="margin-right:8px;">
                        <strong><?php _e('Deactivate Plugins', 'wp-vault'); ?></strong>
                        <span style="color:#666; font-size:12px;"> -
                            <?php _e('Deactivate all plugins before restore (except WP-Vault)', 'wp-vault'); ?></span>
                    </label>
                    <label style="display:block; margin:8px 0;">
                        <input type="checkbox" name="restore_option" value="switch_theme" style="margin-right:8px;">
                        <strong><?php _e('Switch to Default Theme', 'wp-vault'); ?></strong>
                        <span style="color:#666; font-size:12px;"> -
                            <?php _e('Switch to default theme before restore', 'wp-vault'); ?></span>
                    </label>
                    <label style="display:block; margin:8px 0;">
                        <input type="checkbox" name="restore_option" value="pre_restore_backup" checked
                            style="margin-right:8px;">
                        <strong><?php _e('Create Pre-Restore Backup', 'wp-vault'); ?></strong>
                        <span style="color:#666; font-size:12px;"> -
                            <?php _e('Create backup of current site before restoring (recommended)', 'wp-vault'); ?></span>
                    </label>
                </div>
            </div>

            <div style="margin-top:25px; text-align:right; border-top:1px solid #e2e4e7; padding-top:15px;">
                <button type="button" class="button" id="wpv-cancel-restore-options"
                    style="margin-right:10px;"><?php _e('Cancel', 'wp-vault'); ?></button>
                <button type="button" class="button button-primary" id="wpv-confirm-restore-options">
                    <?php _e('Start Restore', 'wp-vault'); ?>
                </button>
            </div>
        </div>
    </div>

    <!-- Restore Progress Modal -->
    <div id="wpv-restore-modal"
        style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999;">
        <div
            style="background:#fff; width:500px; margin:100px auto; padding:20px; border-radius:5px; box-shadow:0 0 10px rgba(0,0,0,0.3);">
            <h3 style="margin-top:0;"><?php _e('Restore in Progress...', 'wp-vault'); ?></h3>

            <div class="wpv-progress-bar"
                style="background:#f0f0f0; height:20px; border-radius:10px; overflow:hidden; margin:15px 0;">
                <div id="wpv-restore-progress-fill"
                    style="background:#2271b1; height:100%; width:0%; transition:width 0.3s;"></div>
            </div>

            <div id="wpv-restore-progress-text" style="font-weight:bold; text-align:center; margin-bottom:10px;">0%
            </div>
            <div id="wpv-restore-progress-message" style="color:#666; font-style:italic; text-align:center;">
                Initializing...</div>

            <div id="wpv-restore-log-feed"
                style="background:#f8f9fa; border:1px solid #e2e4e7; border-radius:4px; height:180px; overflow:auto; padding:10px; font-family:monospace; font-size:12px; margin-top:12px;">
                <div style="color:#888;">Waiting for restore logs...</div>
            </div>

            <div id="wpv-restore-modal-actions" style="margin-top:20px; text-align:right; display:none;">
                <button class="button button-primary"
                    onclick="location.reload()"><?php _e('Close & Refresh', 'wp-vault'); ?></button>
            </div>
        </div>
    </div>

    <style>
        .wpv-status {
            padding: 4px 8px;
            border-radius: 3px;
            font-size: 12px;
            font-weight: 600;
        }

        .wpv-backup-row {
            cursor: default;
        }

        .wpv-backup-components {
            background-color: #f9f9f9;
        }

        .wpv-backup-components td {
            padding: 0 !important;
        }

        .wpv-backup-components table {
            margin: 0;
            border: none;
        }

        .wpv-expand-toggle {
            transition: transform 0.2s;
        }

        .wpv-expand-toggle.dashicons-arrow-down {
            transform: rotate(90deg);
        }
    </style>

    <script>
        jQuery(document).ready(function ($) {
            var currentBackupFile = null;
            var currentBackupPath = null;

            // Restore backup - show options modal first
            $(document).on('click', '.wpv-restore-backup-btn', function () {
                var $btn = $(this);
                currentBackupFile = $btn.data('backup-file');
                currentBackupPath = $btn.data('backup-path');
                var backupId = $btn.data('backup-id');

                // Store backup ID for restore
                $('#wpv-restore-options-modal').data('backup-id', backupId);

                // Reset checkboxes to defaults
                $('input[name="restore_component"]').prop('checked', true);
                $('input[name="restore_option"][value="pre_restore_backup"]').prop('checked', true);
                $('input[name="restore_option"]:not([value="pre_restore_backup"])').prop('checked', false);

                // Show options modal
                $('#wpv-restore-options-modal').show();
            });

            // Cancel restore options
            $('#wpv-cancel-restore-options').on('click', function () {
                $('#wpv-restore-options-modal').hide();
                currentBackupFile = null;
                currentBackupPath = null;
            });

            // Confirm restore with options
            $('#wpv-confirm-restore-options').on('click', function () {
                // Get selected components
                var components = [];
                $('input[name="restore_component"]:checked').each(function () {
                    components.push($(this).val());
                });

                if (components.length === 0) {
                    alert('<?php _e('Please select at least one component to restore.', 'wp-vault'); ?>');
                    return;
                }

                // Get selected options
                var options = {};
                $('input[name="restore_option"]:checked').each(function () {
                    options[$(this).val()] = true;
                });

                // Get backup ID from modal
                var backupId = $('#wpv-restore-options-modal').data('backup-id');

                // Determine restore mode
                var restoreMode = 'full';
                if (components.length === 1) {
                    if (components[0] === 'database') {
                        restoreMode = 'database';
                    } else {
                        restoreMode = 'files';
                    }
                }

                // Hide options modal, show progress modal
                $('#wpv-restore-options-modal').hide();
                $('#wpv-restore-modal').show();
                $('#wpv-restore-progress-fill').css('width', '0%');
                $('#wpv-restore-progress-text').text('0%');
                $('#wpv-restore-progress-message').text('<?php _e('Starting restore...', 'wp-vault'); ?>');
                $('#wpv-restore-log-feed').html('<div style="color:#888;"><?php _e('Waiting for logs...', 'wp-vault'); ?></div>');
                $('#wpv-restore-modal-actions').hide();

                // Start restore with options
                $.post(wpVault.ajax_url, {
                    action: 'wpv_restore_backup',
                    backup_id: backupId,
                    backup_file: currentBackupFile,
                    backup_path: currentBackupPath,
                    restore_mode: restoreMode,
                    components: components,
                    restore_options: options,
                    nonce: wpVault.nonce
                }, function (response) {
                    if (response.success) {
                        var restoreId = response.data.restore_id;
                        pollRestoreProgress(restoreId);
                    } else {
                        alert('<?php _e('Restore failed to start:', 'wp-vault'); ?> ' + (response.data.error || '<?php _e('Unknown error', 'wp-vault'); ?>'));
                        $('#wpv-restore-modal').hide();
                    }
                }).fail(function () {
                    alert('<?php _e('Network error starting restore', 'wp-vault'); ?>');
                    $('#wpv-restore-modal').hide();
                });
            });

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

            // Download backup (all components)
            $('.wpv-download-backup-btn').on('click', function () {
                var $btn = $(this);
                var backupId = $btn.data('backup-id');

                // Download manifest and all component files as a ZIP
                window.location.href = wpVault.ajax_url + '?action=wpv_download_backup&backup_id=' + encodeURIComponent(backupId) + '&nonce=' + wpVault.nonce;
            });

            // Delete backup (all components)
            $('.wpv-delete-backup-btn').on('click', function () {
                if (!confirm('<?php _e('Are you sure you want to delete this backup and all its components?', 'wp-vault'); ?>')) {
                    return;
                }

                var $btn = $(this);
                var backupId = $btn.data('backup-id');

                $.post(wpVault.ajax_url, {
                    action: 'wpv_delete_backup',
                    backup_id: backupId,
                    nonce: wpVault.nonce
                }, function (response) {
                    if (response.success) {
                        // Remove both the main row and components row
                        $('.wpv-backup-row[data-backup-id="' + backupId + '"], .wpv-backup-components[data-backup-id="' + backupId + '"]').fadeOut(function () {
                            $(this).remove();
                        });
                    } else {
                        alert('<?php _e('Failed to delete backup:', 'wp-vault'); ?> ' + (response.data.error || '<?php _e('Unknown error', 'wp-vault'); ?>'));
                    }
                });
            });

            function pollRestoreProgress(restoreId) {
                var pollInterval = setInterval(function () {
                    $.post(wpVault.ajax_url, {
                        action: 'wpv_get_restore_status',
                        restore_id: restoreId,
                        nonce: wpVault.nonce
                    }, function (response) {
                        if (response.success) {
                            var status = response.data.status;
                            var progress = response.data.progress;
                            var message = response.data.message;
                            var logs = response.data.logs || [];

                            // Debug logging
                            console.log('[WP Vault] Restore status:', status, 'Progress:', progress + '%');

                            $('#wpv-restore-progress-fill').css('width', progress + '%');
                            $('#wpv-restore-progress-text').text(progress + '%');
                            $('#wpv-restore-progress-message').text(status + (message ? ': ' + message : ''));
                            renderRestoreLogs(logs);

                            if (status === 'completed' || status === 'failed') {
                                console.log('[WP Vault] Restore finished with status:', status);
                                clearInterval(pollInterval);
                                $('#wpv-restore-modal-actions').show();

                                if (status === 'completed') {
                                    $('#wpv-restore-progress-fill').css('background', '#46b450');
                                    $('#wpv-restore-progress-message').text('<?php _e('Restore completed successfully!', 'wp-vault'); ?>');
                                    setTimeout(function () {
                                        $('#wpv-restore-modal').hide();
                                        location.reload();
                                    }, 3000);
                                } else {
                                    $('#wpv-restore-progress-fill').css('background', '#dc3232');
                                }
                            }
                        } else {
                            console.error('[WP Vault] Error getting restore status:', response.data);
                        }
                    }).fail(function (xhr, status, error) {
                        console.error('[WP Vault] AJAX request failed:', status, error);
                    });
                }, 1000);
            }

            function renderRestoreLogs(logs) {
                var $feed = $('#wpv-restore-log-feed');
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
        });
    </script>
</div>