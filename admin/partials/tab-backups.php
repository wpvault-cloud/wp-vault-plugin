<?php
/**
 * Backups Tab Content
 * 
 * Main backup controls and backup listing
 *
 * @package WP_Vault
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Display backups tab content
 */
function wpvault_display_backups_tab()
{
    require_once WP_VAULT_PLUGIN_DIR . 'includes/class-wp-vault-api.php';
    require_once WP_VAULT_PLUGIN_DIR . 'includes/class-wp-vault.php';

    $api = new \WP_Vault\WP_Vault_API();
    $registered = (bool) get_option('wpv_site_id');

    // Get compression mode info
    $compression_info = \WP_Vault\WP_Vault::get_compression_mode_info();

    // Get backups from SaaS API
    $backups_result = $api->get_backups();
    $saas_backups = $backups_result['success'] ? $backups_result['data']['backups'] : array();

    // Sync remote backups to local history
    global $wpdb;
    $wp_vault_instance = \WP_Vault\WP_Vault::get_instance();
    $wp_vault_instance->sync_remote_backups_to_history($saas_backups);

    // Get local backup history from database
    $history_table = $wpdb->prefix . 'wp_vault_backup_history';
    $local_history = $wpdb->get_results(
        "SELECT * FROM {$history_table} ORDER BY created_at DESC",
        ARRAY_A
    );

    // Get list of backups that have been successfully restored from
    $jobs_table = $wpdb->prefix . 'wp_vault_jobs';
    $restored_backup_ids = $wpdb->get_col(
        "SELECT DISTINCT source_backup_id 
         FROM {$jobs_table} 
         WHERE job_type = 'restore' AND status IN ('restored', 'completed') AND source_backup_id IS NOT NULL"
    );
    $restored_from_map = array_flip($restored_backup_ids); // Create quick lookup map

    $restored_from_map = array_flip($restored_backup_ids); // Create quick lookup map

    // Get local backup files (grouped by backup_id)
    $backup_dir = WP_CONTENT_DIR . '/wp-vault-backups/';
    $local_backups = array();
    $backups_by_id = array();

    if (is_dir($backup_dir)) {
        // Read manifest files to get backup information
        $manifest_files = glob($backup_dir . 'backup-*-manifest.json');
        foreach ($manifest_files as $manifest_file) {
            $manifest_data = json_decode(file_get_contents($manifest_file), true);
            if ($manifest_data && isset($manifest_data['backup_id'])) {
                $backup_id = $manifest_data['backup_id'];

                // Calculate actual total size
                $actual_total_size = 0;
                $actual_files = array();
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

                // Transform components
                $components = array();
                if (isset($manifest_data['components'])) {
                    $manifest_components = $manifest_data['components'];
                    if (is_array($manifest_components)) {
                        if (isset($manifest_components['themes']) || isset($manifest_components['plugins'])) {
                            // Old format
                            foreach ($manifest_components as $component_name => $component_files) {
                                if (is_array($component_files) && !empty($component_files)) {
                                    $component_archives = array();
                                    foreach ($component_files as $file) {
                                        $filename = is_array($file) ? (isset($file['filename']) ? $file['filename'] : '') : $file;
                                        if (!empty($filename)) {
                                            $component_archives[] = $filename;
                                        }
                                    }
                                    if (!empty($component_archives)) {
                                        $components[] = array(
                                            'name' => $component_name,
                                            'archives' => $component_archives
                                        );
                                    }
                                }
                            }
                        } else {
                            // New format
                            foreach ($manifest_components as $comp) {
                                if (is_array($comp) && isset($comp['name'])) {
                                    $components[] = $comp;
                                }
                            }
                        }
                    }
                }

                $backups_by_id[$backup_id] = array(
                    'backup_id' => $backup_id,
                    'backup_type' => isset($manifest_data['backup_type']) ? $manifest_data['backup_type'] : 'full',
                    'total_size' => $actual_total_size > 0 ? $actual_total_size : (isset($manifest_data['total_size']) ? $manifest_data['total_size'] : 0),
                    'created_at' => isset($manifest_data['created_at']) ? $manifest_data['created_at'] : gmdate('Y-m-d H:i:s', filemtime($manifest_file)),
                    'date' => filemtime($manifest_file),
                    'components' => $components,
                    'files' => $actual_files,
                );
            }
        }

        // Convert to array and sort
        $local_backups = array_values($backups_by_id);
        usort($local_backups, function ($a, $b) {
            return $b['date'] - $a['date'];
        });
    }

    // Create a map of backup history by backup_id
    $history_map = array();
    foreach ($local_history as $history_item) {
        $history_map[$history_item['backup_id']] = $history_item;
    }

    // Merge SaaS and local backups using history
    $all_backups = array();
    $processed_backup_ids = array();

    foreach ($saas_backups as $backup) {
        $backup_id = $backup['id'];
        $processed_backup_ids[] = $backup_id;

        $components = array();
        $files = array();

        if (isset($backup['components']) && is_array($backup['components']) && !empty($backup['components'])) {
            foreach ($backup['components'] as $comp) {
                $component_name = isset($comp['name']) ? $comp['name'] : '';
                $component_objects = isset($comp['objects']) ? $comp['objects'] : array();

                if (empty($component_objects)) {
                    continue;
                }

                $component_archives = array();
                foreach ($component_objects as $obj) {
                    $object_key = isset($obj['key']) ? $obj['key'] : '';
                    $object_size = isset($obj['size']) ? $obj['size'] : 0;
                    $filename = basename($object_key);
                    if (empty($filename)) {
                        $filename = $component_name . '.tar.gz';
                    }

                    $component_archives[] = $object_key;
                    $files[] = array(
                        'filename' => $filename,
                        'path' => $object_key,
                        'size' => $object_size,
                        'component' => $component_name,
                        'is_cloud' => true
                    );
                }

                if (!empty($component_archives)) {
                    $components[] = array(
                        'name' => $component_name,
                        'archives' => $component_archives,
                        'objects' => $component_objects,
                        'total_size' => isset($comp['total_size']) ? $comp['total_size'] : 0
                    );
                }
            }
        }

        // Get history data if available
        $history_data = isset($history_map[$backup_id]) ? $history_map[$backup_id] : null;

        // Determine source badge
        $has_local = $history_data ? (intval($history_data['has_local_files']) === 1) : false;
        $has_remote = $history_data ? (intval($history_data['has_remote_files']) === 1) : true;

        if ($has_local && $has_remote) {
            $source_badge = 'both';
            $source_label = 'Local & Remote';
        } elseif ($has_local) {
            $source_badge = 'local';
            $source_label = 'Local Only';
        } else {
            $source_badge = 'remote';
            $source_label = 'Remote Only';
        }

        $all_backups[] = array(
            'backup_id' => $backup_id,
            'backup_type' => isset($backup['backup_type']) ? $backup['backup_type'] : ($history_data ? $history_data['backup_type'] : 'full'),
            'status' => isset($backup['status']) ? $backup['status'] : ($history_data ? $history_data['status'] : 'unknown'),
            'total_size' => isset($backup['total_size_bytes']) ? $backup['total_size_bytes'] : ($history_data ? intval($history_data['total_size_bytes']) : 0),
            'created_at' => isset($backup['created_at']) ? $backup['created_at'] : ($history_data ? $history_data['created_at'] : gmdate('Y-m-d H:i:s')),
            'date' => isset($backup['finished_at']) ? strtotime($backup['finished_at']) : (isset($backup['created_at']) ? strtotime($backup['created_at']) : ($history_data && $history_data['finished_at'] ? strtotime($history_data['finished_at']) : ($history_data && $history_data['created_at'] ? strtotime($history_data['created_at']) : time()))),
            'source' => $source_badge,
            'source_label' => $source_label,
            'has_local_files' => $has_local,
            'has_remote_files' => $has_remote,
            'files' => $files,
            'components' => $components,
            'restored_from' => isset($restored_from_map[$backup_id]), // Check if this backup has been restored
        );
    }

    // Add local backups from history that aren't in SaaS
    foreach ($local_history as $history_item) {
        $backup_id = $history_item['backup_id'];
        if (!in_array($backup_id, $processed_backup_ids)) {
            $backup_dir = WP_CONTENT_DIR . '/wp-vault-backups/';
            $manifest_file = $backup_dir . 'backup-' . $backup_id . '-manifest.json';
            $has_local = file_exists($manifest_file);

            $local_backup_data = null;
            foreach ($local_backups as $lb) {
                if ($lb['backup_id'] === $backup_id) {
                    $local_backup_data = $lb;
                    break;
                }
            }

            $source_badge = $has_local ? 'local' : 'remote';
            $source_label = $has_local ? 'Local Only' : 'Remote Only';

            $all_backups[] = array(
                'backup_id' => $backup_id,
                'backup_type' => $history_item['backup_type'],
                'status' => $history_item['status'],
                'total_size' => intval($history_item['total_size_bytes']),
                'created_at' => $history_item['created_at'],
                'date' => $history_item['finished_at'] ? strtotime($history_item['finished_at']) : strtotime($history_item['created_at']),
                'source' => $source_badge,
                'source_label' => $source_label,
                'has_local_files' => $has_local,
                'has_remote_files' => intval($history_item['has_remote_files']) === 1,
                'files' => $local_backup_data ? $local_backup_data['files'] : array(),
                'components' => $local_backup_data ? $local_backup_data['components'] : array(),
                'restored_from' => isset($restored_from_map[$backup_id]), // Check if this backup has been restored
            );
        }
    }

    // Sort by date
    usort($all_backups, function ($a, $b) {
        return $b['date'] - $a['date'];
    });

    $backups = $all_backups;
    ?>

    <div class="wpv-tab-content" id="wpv-tab-backups">
        <?php
        // Show unclosable notice if compression mode is not set
        if (empty($compression_info['mode']) || !$compression_info['available']):
            ?>
            <div class="notice notice-error"
                style="margin: 0; padding: 12px 20px; border-left-color: #d63638; background: #fff5f5; border-radius: 0;">
                <p
                    style="margin: 0; padding: 0; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 15px;">
                    <span style="display: flex; align-items: center; gap: 10px; color: #d63638; font-weight: 600;">
                        <span class="dashicons dashicons-warning" style="font-size: 20px; width: 20px; height: 20px;"></span>
                        <span><?php esc_html_e('Please set the compression mode to enable backups and restores.', 'wp-vault'); ?></span>
                    </span>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=wp-vault&tab=settings')); ?>"
                        class="button button-primary"
                        style="background: #d63638; border-color: #d63638; color: #fff; font-weight: 600;">
                        <?php esc_html_e('Go to Settings', 'wp-vault'); ?>
                    </a>
                </p>
            </div>
        <?php endif; ?>

        <!-- Backup Controls Section with Two-Column Layout -->
        <div class="wpv-section">
            <h2><?php esc_html_e('Back Up Manually', 'wp-vault'); ?></h2>

            <div class="wpv-backup-layout"
                style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-top: 20px;">
                <!-- Left Column: Backup Options -->
                <div class="wpv-backup-options-column" style="min-width: 0;">
                    <h3 style="margin-top: 0;"><?php esc_html_e('What to Backup', 'wp-vault'); ?></h3>
                    <div class="wpv-backup-controls">
                        <div class="wpv-backup-options">
                            <label class="wpv-radio-option">
                                <input type="radio" name="backup_content" value="full" checked>
                                <span><?php esc_html_e('Database + Files (WordPress Files)', 'wp-vault'); ?></span>
                            </label>
                            <label class="wpv-radio-option">
                                <input type="radio" name="backup_content" value="files">
                                <span><?php esc_html_e('WordPress Files (Exclude Database)', 'wp-vault'); ?></span>
                            </label>
                            <label class="wpv-radio-option">
                                <input type="radio" name="backup_content" value="database">
                                <span><?php esc_html_e('Only Database', 'wp-vault'); ?></span>
                            </label>
                            <label class="wpv-radio-option">
                                <input type="radio" name="backup_content" value="incremental">
                                <span><?php esc_html_e('Incremental Backup', 'wp-vault'); ?> <span
                                        class="wpv-badge wpv-badge-pro">Pro</span></span>
                            </label>
                        </div>

                        <div class="wpv-backup-actions" style="margin-top: 20px;">
                            <?php
                            $compression_mode_selected = !empty($compression_info['mode']) && $compression_info['available'];
                            $disabled_attr = $compression_mode_selected ? '' : 'disabled';
                            $disabled_title = $compression_mode_selected ? '' : ' title="' . esc_attr__('Please select an available compression mode in Settings before creating backups.', 'wp-vault') . '"';
                            ?>
                            <button id="wpv-backup-now" class="button button-primary button-large" <?php echo esc_attr($disabled_attr . $disabled_title); ?>>
                                <span class="dashicons dashicons-cloud-upload"></span>
                                <?php esc_html_e('Backup Now', 'wp-vault'); ?>
                            </button>
                        </div>
                    </div>

                    <p class="wpv-tip" style="margin-top: 15px; font-size: 13px; color: #646970;">
                        <?php esc_html_e('Tip: The settings are only for manual backup, which won\'t affect schedule settings.', 'wp-vault'); ?>
                    </p>
                </div>

                <!-- Right Column: Information Panel -->
                <div class="wpv-backup-info-column" style="min-width: 0;">
                    <h3 style="margin-top: 0;"><?php esc_html_e('Backup Information', 'wp-vault'); ?></h3>

                    <?php
                    // Get last backup info
                    $last_backup = !empty($backups) ? $backups[0] : null;
                    $total_backups = count($backups);
                    $total_size = 0;
                    foreach ($backups as $backup) {
                        $total_size += isset($backup['total_size']) ? $backup['total_size'] : 0;
                    }
                    $file_split_size = get_option('wpv_file_split_size', 200);
                    ?>

                    <div style="display: flex; flex-direction: column; gap: 15px;">
                        <!-- Compression Mode Card -->
                        <div
                            style="background: #fff; border: 1px solid #c3c4c7; border-radius: 6px; padding: 16px; box-shadow: 0 1px 2px rgba(0,0,0,0.05);">
                            <div
                                style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px;">
                                <div style="display: flex; align-items: center; gap: 8px;">
                                    <span class="dashicons dashicons-performance"
                                        style="color: #2271b1; font-size: 18px; width: 18px; height: 18px;"></span>
                                    <strong
                                        style="font-size: 13px; text-transform: uppercase; letter-spacing: 0.5px; color: #50575e;"><?php esc_html_e('Compression Mode', 'wp-vault'); ?></strong>
                                </div>
                                <?php if ($compression_info['available']): ?>
                                    <span
                                        style="background: #00a32a; color: #fff; padding: 3px 8px; border-radius: 12px; font-size: 11px; font-weight: 600; display: inline-flex; align-items: center; gap: 4px;">
                                        <span>✓</span> <?php esc_html_e('Available', 'wp-vault'); ?>
                                    </span>
                                <?php else: ?>
                                    <span
                                        style="background: #d63638; color: #fff; padding: 3px 8px; border-radius: 12px; font-size: 11px; font-weight: 600; display: inline-flex; align-items: center; gap: 4px;">
                                        <span>✗</span> <?php esc_html_e('Unavailable', 'wp-vault'); ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                            <div style="background: #f6f7f7; border-radius: 4px; padding: 12px; margin-bottom: 10px;">
                                <div style="font-size: 15px; font-weight: 600; color: #1d2327; margin-bottom: 4px;">
                                    <?php echo esc_html($compression_info['label']); ?>
                                </div>
                                <div style="font-size: 12px; color: #646970; line-height: 1.5;">
                                    <?php echo esc_html($compression_info['description']); ?>
                                </div>
                            </div>
                            <a href="<?php echo esc_url($compression_info['settings_url']); ?>"
                                style="font-size: 12px; color: #2271b1; text-decoration: none; display: inline-flex; align-items: center; gap: 4px;">
                                <?php esc_html_e('Change in Settings', 'wp-vault'); ?> <span
                                    class="dashicons dashicons-arrow-right-alt2"
                                    style="font-size: 14px; width: 14px; height: 14px;"></span>
                            </a>
                        </div>

                        <!-- Last Backup and Statistics in Single Row -->
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                            <!-- Last Backup Card -->
                            <div
                                style="background: #fff; border: 1px solid #c3c4c7; border-radius: 6px; padding: 16px; box-shadow: 0 1px 2px rgba(0,0,0,0.05);">
                                <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 12px;">
                                    <span class="dashicons dashicons-clock"
                                        style="color: #2271b1; font-size: 18px; width: 18px; height: 18px;"></span>
                                    <strong
                                        style="font-size: 13px; text-transform: uppercase; letter-spacing: 0.5px; color: #50575e;"><?php esc_html_e('Last Backup', 'wp-vault'); ?></strong>
                                </div>
                                <?php if ($last_backup): ?>
                                    <div style="display: flex; flex-direction: column; gap: 8px;">
                                        <div style="font-size: 16px; font-weight: 600; color: #1d2327;">
                                            <?php echo esc_html(gmdate('M j, Y g:i a', strtotime($last_backup['created_at']))); ?>
                                        </div>
                                        <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                                            <span
                                                style="background: #f0f6fc; color: #0969da; padding: 4px 10px; border-radius: 12px; font-size: 12px; font-weight: 600; display: inline-flex; align-items: center; gap: 4px;">
                                                <span class="dashicons dashicons-database"
                                                    style="font-size: 14px; width: 14px; height: 14px;"></span>
                                                <?php echo esc_html(size_format($last_backup['total_size'])); ?>
                                            </span>
                                            <span
                                                style="background: #fff3cd; color: #856404; padding: 4px 10px; border-radius: 12px; font-size: 12px; font-weight: 600; display: inline-flex; align-items: center; gap: 4px;">
                                                <span class="dashicons dashicons-admin-generic"
                                                    style="font-size: 14px; width: 14px; height: 14px;"></span>
                                                <?php echo esc_html(ucfirst($last_backup['backup_type'])); ?>
                                            </span>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <div style="background: #f6f7f7; border-radius: 4px; padding: 12px; text-align: center;">
                                        <span class="dashicons dashicons-info-outline"
                                            style="color: #646970; font-size: 20px; width: 20px; height: 20px; display: block; margin: 0 auto 8px;"></span>
                                        <div style="font-size: 13px; color: #646970;">
                                            <?php esc_html_e('No backups yet', 'wp-vault'); ?>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <!-- Stats Card (Total Backups & Storage) -->
                            <div
                                style="background: linear-gradient(135deg, #f6f7f7 0%, #ffffff 100%); border: 1px solid #c3c4c7; border-radius: 6px; padding: 16px; box-shadow: 0 1px 2px rgba(0,0,0,0.05);">
                                <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 12px;">
                                    <span class="dashicons dashicons-chart-bar"
                                        style="color: #2271b1; font-size: 18px; width: 18px; height: 18px;"></span>
                                    <strong
                                        style="font-size: 13px; text-transform: uppercase; letter-spacing: 0.5px; color: #50575e;"><?php esc_html_e('Statistics', 'wp-vault'); ?></strong>
                                </div>
                                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                                    <div
                                        style="background: #fff; border-radius: 4px; padding: 12px; text-align: center; border: 1px solid #e2e4e7;">
                                        <div style="font-size: 24px; font-weight: 700; color: #1d2327; margin-bottom: 4px;">
                                            <?php echo esc_html($total_backups); ?>
                                        </div>
                                        <div
                                            style="font-size: 11px; color: #646970; text-transform: uppercase; letter-spacing: 0.5px;">
                                            <?php esc_html_e('Backups', 'wp-vault'); ?>
                                        </div>
                                    </div>
                                    <div
                                        style="background: #fff; border-radius: 4px; padding: 12px; text-align: center; border: 1px solid #e2e4e7;">
                                        <div
                                            style="font-size: 20px; font-weight: 700; color: #1d2327; margin-bottom: 4px; line-height: 1.2;">
                                            <?php echo esc_html(size_format($total_size)); ?>
                                        </div>
                                        <div
                                            style="font-size: 11px; color: #646970; text-transform: uppercase; letter-spacing: 0.5px;">
                                            <?php esc_html_e('Storage', 'wp-vault'); ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- File Split Size Card -->
                        <!--                         <div
                            style="background: #fff; border: 1px solid #c3c4c7; border-radius: 6px; padding: 16px; box-shadow: 0 1px 2px rgba(0,0,0,0.05);">
                            <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 12px;">
                                <span class="dashicons dashicons-admin-settings"
                                    style="color: #2271b1; font-size: 18px; width: 18px; height: 18px;"></span>
                                <strong
                                    style="font-size: 13px; text-transform: uppercase; letter-spacing: 0.5px; color: #50575e;"><?php esc_html_e('File Split Size', 'wp-vault'); ?></strong>
                            </div>
                            <div style="display: flex; align-items: center; gap: 12px;">
                                <div
                                    style="background: linear-gradient(135deg, #2271b1 0%, #1d4ed8 100%); color: #fff; padding: 10px 16px; border-radius: 6px; font-size: 18px; font-weight: 700; min-width: 70px; text-align: center;">
                                    <?php echo esc_html($file_split_size); ?>
                                    <div style="font-size: 11px; font-weight: 500; opacity: 0.9; margin-top: 2px;">MB</div>
                                </div>
                                <div style="flex: 1;">
                                    <div style="font-size: 12px; color: #646970; line-height: 1.5;">
                                        <?php esc_html_e('Backups will be split when size exceeds this limit', 'wp-vault'); ?>
                                    </div>
                                </div>
                            </div>
                        </div> -->
                    </div>
                </div>
            </div>
        </div>

        <!-- Backups List Section -->
        <div class="wpv-section">
            <h2><?php esc_html_e('Existing Backups', 'wp-vault'); ?></h2>

            <?php if (empty($backups)): ?>
                <div class="wpv-empty-state">
                    <p><?php esc_html_e('No backups found. Create a backup using the controls above to get started.', 'wp-vault'); ?>
                    </p>
                </div>
            <?php else: ?>
                <p class="wpv-backup-summary">
                    <?php
                    $saas_count = count(array_filter($backups, function ($b) {
                        return isset($b['source']) && $b['source'] === 'saas';
                    }));
                    $local_count = count(array_filter($backups, function ($b) {
                        return !isset($b['source']) || $b['source'] === 'local';
                    }));
                    if ($saas_count > 0 && $local_count > 0) {
                        /* translators: 1: total backup count, 2: cloud backup count, 3: local backup count */
                        printf(esc_html__('Found %1$d backup(s): %2$d in cloud, %3$d local', 'wp-vault'), count($backups), absint($saas_count), absint($local_count));
                    } elseif ($saas_count > 0) {
                        /* translators: %d: number of backups */
                        printf(esc_html__('Found %d backup(s) stored in cloud', 'wp-vault'), count($backups));
                    } else {
                        /* translators: 1: number of backups, 2: directory path */
                        printf(esc_html__('Found %1$d local backup(s) stored in %2$s', 'wp-vault'), count($backups), '<code>wp-content/wp-vault-backups/</code>');
                    }
                    ?>
                </p>

                <table class="wp-list-table widefat fixed striped" id="wpv-backups-table">
                    <thead>
                        <tr>
                            <th style="width:30px;"></th>
                            <th><?php esc_html_e('Backup History', 'wp-vault'); ?></th>
                            <th style="text-align:center; width:100px;"><?php esc_html_e('Local Backup', 'wp-vault'); ?></th>
                            <th style="text-align:center; width:100px;"><?php esc_html_e('Cloud Backup', 'wp-vault'); ?></th>
                            <th><?php esc_html_e('Size', 'wp-vault'); ?></th>
                            <th><?php esc_html_e('Components', 'wp-vault'); ?></th>
                            <th><?php esc_html_e('Date', 'wp-vault'); ?></th>
                            <th><?php esc_html_e('Actions', 'wp-vault'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($backups as $backup):
                            $backup_id = $backup['backup_id'];
                            $has_components = false;
                            if (!empty($backup['components'])) {
                                foreach ($backup['components'] as $comp) {
                                    $comp_objects = isset($comp['objects']) ? $comp['objects'] : array();
                                    $comp_archives = isset($comp['archives']) ? $comp['archives'] : array();
                                    if (!empty($comp_objects) || !empty($comp_archives)) {
                                        $has_components = true;
                                        break;
                                    }
                                }
                            }
                            if (!$has_components && !empty($backup['files']) && count($backup['files']) > 1) {
                                $has_components = true;
                            }
                            $backup_name = 'Backup ' . substr($backup_id, 0, 8) . '...';
                            $backup_date = strtotime($backup['created_at']);
                            $total_size = $backup['total_size'];
                            if ($total_size === 0 && !empty($backup['files'])) {
                                foreach ($backup['files'] as $file) {
                                    $total_size += isset($file['size']) ? $file['size'] : (file_exists($backup_dir . $file['filename']) ? filesize($backup_dir . $file['filename']) : 0);
                                }
                            }
                            ?>
                            <tr class="wpv-backup-row" data-backup-id="<?php echo esc_attr($backup_id); ?>">
                                <td>
                                    <?php if ($has_components): ?>
                                        <span class="wpv-expand-toggle dashicons dashicons-arrow-right"
                                            style="cursor:pointer; color:#666;"
                                            title="<?php esc_html_e('Click to expand/collapse', 'wp-vault'); ?>"></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <strong><?php echo esc_html($backup_name); ?></strong>
                                    <br>
                                    <small style="color:#666;">ID: <?php echo esc_html($backup_id); ?></small>
                                </td>
                                <td style="text-align:center;">
                                    <?php
                                    // Check if local files actually exist on filesystem (not just database value)
                                    $manifest_file = $backup_dir . 'backup-' . $backup_id . '-manifest.json';
                                    $has_local = file_exists($manifest_file);

                                    // If no manifest, check for component files
                                    if (!$has_local) {
                                        $patterns = array(
                                            $backup_dir . '{database,themes,plugins,uploads,wp-content}-' . $backup_id . '-*.tar.gz',
                                            $backup_dir . '{database,themes,plugins,uploads,wp-content}-' . $backup_id . '-*.sql.gz',
                                            $backup_dir . 'backup-' . $backup_id . '-*.tar.gz',
                                        );
                                        foreach ($patterns as $pattern) {
                                            $files = glob($pattern, GLOB_BRACE);
                                            if ($files && is_array($files) && count($files) > 0) {
                                                $has_local = true;
                                                break;
                                            }
                                        }
                                    }

                                    if ($has_local) {
                                        echo '<span class="dashicons dashicons-yes-alt" style="color:#00a32a; font-size:20px;" title="' . esc_attr__('Local backup available', 'wp-vault') . '"></span>';
                                    } else {
                                        echo '<span class="dashicons dashicons-dismiss" style="color:#d63638; font-size:20px;" title="' . esc_attr__('Local backup not available', 'wp-vault') . '"></span>';
                                    }
                                    ?>
                                </td>
                                <td style="text-align:center;">
                                    <?php
                                    $has_remote = isset($backup['has_remote_files']) ? (bool) $backup['has_remote_files'] : false;
                                    if ($has_remote) {
                                        echo '<span class="dashicons dashicons-yes-alt" style="color:#00a32a; font-size:20px;" title="' . esc_attr__('Cloud backup available', 'wp-vault') . '"></span>';
                                    } else {
                                        echo '<span class="dashicons dashicons-dismiss" style="color:#d63638; font-size:20px;" title="' . esc_attr__('Cloud backup not available', 'wp-vault') . '"></span>';
                                    }
                                    ?>
                                </td>
                                <td>
                                    <strong><?php echo esc_html(size_format($total_size)); ?></strong>
                                    <?php if (isset($backup['status'])): ?>
                                        <br>
                                        <div class="wpv-status-group" style="display:flex; gap:5px; margin-top:3px;">
                                            <span class="wpv-status wpv-status-<?php echo esc_attr($backup['status']); ?>"
                                                style="font-size:11px; display:inline-block;">
                                                <?php echo esc_html(ucfirst($backup['status'])); ?>
                                            </span>
                                            <?php if (!empty($backup['restored_from'])): ?>
                                                <span class="wpv-status wpv-status-restored" style="font-size:11px; display:inline-block;">
                                                    Restored
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php
                                    $component_count = count($backup['components']);
                                    if ($component_count > 0) {
                                        echo absint($component_count) . ' ' . esc_html(_n('component', 'components', $component_count, 'wp-vault'));
                                    } else {
                                        echo '1 ' . esc_html__('file', 'wp-vault');
                                    }
                                    ?>
                                </td>
                                <td><?php echo esc_html(gmdate('M j, Y g:i a', $backup_date)); ?></td>
                                <td class="wpv-backup-actions" data-backup-id="<?php echo esc_attr($backup_id); ?>">
                                    <?php
                                    $primary_file = !empty($backup['files']) ? $backup['files'][0]['filename'] : 'backup-' . $backup_id . '.tar.gz';
                                    $primary_path = $backup_dir . $primary_file;
                                    if (!file_exists($primary_path) && !empty($backup['files'])) {
                                        foreach ($backup['files'] as $file) {
                                            if (file_exists($backup_dir . $file['filename'])) {
                                                $primary_file = $file['filename'];
                                                $primary_path = $backup_dir . $file['filename'];
                                                break;
                                            }
                                        }
                                    }

                                    // Check if backup has local files
                                    $has_local_files = false;
                                    $manifest_file = $backup_dir . 'backup-' . $backup_id . '-manifest.json';
                                    if (file_exists($manifest_file)) {
                                        $has_local_files = true;
                                    } else {
                                        // Check if any component files exist
                                        $patterns = array(
                                            $backup_dir . '{database,themes,plugins,uploads,wp-content}-' . $backup_id . '-*.tar.gz',
                                            $backup_dir . '{database,themes,plugins,uploads,wp-content}-' . $backup_id . '-*.sql.gz',
                                            $backup_dir . 'backup-' . $backup_id . '-*.tar.gz',
                                        );
                                        foreach ($patterns as $pattern) {
                                            $files = glob($pattern, GLOB_BRACE);
                                            if ($files && is_array($files) && count($files) > 0) {
                                                $has_local_files = true;
                                                break;
                                            }
                                        }
                                    }
                                    ?>
                                    <?php if ($has_local_files): ?>
                                        <button class="button button-primary wpv-restore-backup-btn"
                                            data-backup-id="<?php echo esc_attr($backup_id); ?>"
                                            data-backup-file="<?php echo esc_attr($primary_file); ?>"
                                            data-backup-path="<?php echo esc_attr($primary_path); ?>">
                                            <?php esc_html_e('Restore', 'wp-vault'); ?>
                                        </button>
                                        <button class="button wpv-download-backup-btn"
                                            data-backup-id="<?php echo esc_attr($backup_id); ?>" style="margin-left: 5px;">
                                            <?php esc_html_e('Download', 'wp-vault'); ?>
                                        </button>
                                        <button class="button wpv-delete-backup-btn"
                                            data-backup-id="<?php echo esc_attr($backup_id); ?>" style="margin-left: 5px;">
                                            <?php esc_html_e('Delete File', 'wp-vault'); ?>
                                        </button>
                                    <?php else: ?>
                                        <button class="button button-primary wpv-download-from-remote-btn"
                                            data-backup-id="<?php echo esc_attr($backup_id); ?>">
                                            <?php esc_html_e('Download from Remote', 'wp-vault'); ?>
                                        </button>
                                        <button class="button wpv-remove-from-db-btn"
                                            data-backup-id="<?php echo esc_attr($backup_id); ?>" style="margin-left: 5px;">
                                            <?php esc_html_e('Remove from DB', 'wp-vault'); ?>
                                        </button>
                                    <?php endif; ?>
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
                                                    <th style="padding:8px;"><?php esc_html_e('Component', 'wp-vault'); ?></th>
                                                    <th style="padding:8px;"><?php esc_html_e('File', 'wp-vault'); ?></th>
                                                    <th style="padding:8px;"><?php esc_html_e('Size', 'wp-vault'); ?></th>
                                                    <th style="padding:8px;"><?php esc_html_e('Actions', 'wp-vault'); ?></th>
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

                                                if (!empty($backup['components'])) {
                                                    foreach ($backup['components'] as $component) {
                                                        $component_name = isset($component['name']) ? $component['name'] : '';
                                                        $component_label = isset($component_map[$component_name]) ? $component_map[$component_name] : ucfirst($component_name);
                                                        $component_objects = isset($component['objects']) ? $component['objects'] : array();
                                                        $component_archives = isset($component['archives']) ? $component['archives'] : array();
                                                        $items_to_show = !empty($component_objects) ? $component_objects : $component_archives;

                                                        foreach ($items_to_show as $item) {
                                                            if (is_array($item)) {
                                                                $archive_path = isset($item['key']) ? $item['key'] : '';
                                                                $archive_filename = basename($archive_path) ?: $component_name . '.tar.gz';
                                                                $file_size = isset($item['size']) ? $item['size'] : 0;
                                                                $is_cloud = isset($backup['source']) && $backup['source'] === 'saas';
                                                            } else {
                                                                $archive_path = $item;
                                                                $archive_filename = basename($archive_path);
                                                                $is_cloud = false;
                                                            }

                                                            // Find matching file from backup['files'] array
                                                            // Match by component name prefix in filename (e.g., "database-", "themes-", "plugins-")
                                                            $file_info = null;
                                                            if (!empty($backup['files'])) {
                                                                foreach ($backup['files'] as $file) {
                                                                    $filename = isset($file['filename']) ? $file['filename'] : '';
                                                                    // Check if filename starts with component name
                                                                    if (!empty($filename) && strpos($filename, $component_name . '-') === 0) {
                                                                        $file_info = $file;
                                                                        // Ensure path is set correctly
                                                                        if (!isset($file_info['path']) && isset($file_info['filename'])) {
                                                                            $file_info['path'] = $backup_dir . $file_info['filename'];
                                                                        }
                                                                        break;
                                                                    }
                                                                }
                                                            }

                                                            // If still no match and not cloud, try to find file by component name pattern
                                                            if (!$file_info && !$is_cloud && !empty($backup_id)) {
                                                                // Try to find file matching component name pattern: component-backupid-*.ext
                                                                $pattern = $component_name . '-' . $backup_id . '*';
                                                                $matching_files = glob($backup_dir . $pattern);
                                                                if (!empty($matching_files)) {
                                                                    $found_file = $matching_files[0];
                                                                    $found_filename = basename($found_file);
                                                                    if (file_exists($found_file)) {
                                                                        $file_info = array(
                                                                            'filename' => $found_filename,
                                                                            'path' => $found_file,
                                                                            'size' => filesize($found_file),
                                                                        );
                                                                    }
                                                                }
                                                            }

                                                            // Use file_info if available, otherwise construct from archive path
                                                            if ($file_info) {
                                                                $display_filename = $file_info['filename'];
                                                                $local_file_path = isset($file_info['path']) ? $file_info['path'] : $backup_dir . $display_filename;
                                                                // Always get actual file size from filesystem if file exists
                                                                if (file_exists($local_file_path)) {
                                                                    $display_size = filesize($local_file_path);
                                                                } else {
                                                                    $display_size = isset($file_info['size']) ? $file_info['size'] : 0;
                                                                }
                                                            } else {
                                                                $display_filename = $archive_filename;
                                                                $local_file_path = $backup_dir . $display_filename;
                                                                // Try to get actual size from filesystem
                                                                if (file_exists($local_file_path)) {
                                                                    $display_size = filesize($local_file_path);
                                                                } else {
                                                                    $display_size = $file_size;
                                                                }
                                                            }
                                                            ?>
                                                            <tr>
                                                                <td style="padding:8px;"><strong><?php echo esc_html($component_label); ?></strong>
                                                                </td>
                                                                <td style="padding:8px;">
                                                                    <code style="font-size:11px;"><?php echo esc_html($display_filename); ?></code>
                                                                    <?php if ($is_cloud): ?>
                                                                        <br><small style="color:#2271b1;">☁️ Cloud Storage</small>
                                                                    <?php endif; ?>
                                                                </td>
                                                                <td style="padding:8px;"><?php echo esc_html(size_format($display_size)); ?></td>
                                                                <td style="padding:8px;">
                                                                    <?php if ($is_cloud): ?>
                                                                        <span
                                                                            style="color:#666; font-size:11px;"><?php esc_html_e('Stored in cloud', 'wp-vault'); ?></span>
                                                                    <?php else: ?>
                                                                        <?php
                                                                        if (file_exists($local_file_path)): ?>
                                                                            <a href="<?php echo esc_url(admin_url('admin-ajax.php?action=wpv_download_backup_file&file=' . urlencode($display_filename) . '&nonce=' . wp_create_nonce('wp-vault'))); ?>"
                                                                                class="button button-small">
                                                                                <?php esc_html_e('Download', 'wp-vault'); ?>
                                                                            </a>
                                                                        <?php else: ?>
                                                                            <span
                                                                                style="color:#999; font-size:11px;"><?php esc_html_e('File not found', 'wp-vault'); ?></span>
                                                                        <?php endif; ?>
                                                                    <?php endif; ?>
                                                                </td>
                                                            </tr>
                                                            <?php
                                                        }
                                                    }
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
        </div>
    </div>
    <?php
}

// Call the function to display the tab
wpvault_display_backups_tab();