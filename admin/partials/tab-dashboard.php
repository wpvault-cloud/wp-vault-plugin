<?php
/**
 * Dashboard Tab Content
 * 
 * Shows overview with last 5 backups
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

// Get backups from SaaS API
$backups_result = $api->get_backups();
$saas_backups = $backups_result['success'] ? $backups_result['data']['backups'] : array();

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
                'created_at' => isset($manifest_data['created_at']) ? $manifest_data['created_at'] : date('Y-m-d H:i:s', filemtime($manifest_file)),
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

// Merge SaaS and local backups
$all_backups = array();
$saas_backup_ids = array();

foreach ($saas_backups as $backup) {
    $backup_id = $backup['id'];
    $saas_backup_ids[] = $backup_id;
    
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
    
    $all_backups[] = array(
        'backup_id' => $backup_id,
        'backup_type' => isset($backup['backup_type']) ? $backup['backup_type'] : 'full',
        'status' => isset($backup['status']) ? $backup['status'] : 'unknown',
        'total_size' => isset($backup['total_size_bytes']) ? $backup['total_size_bytes'] : 0,
        'created_at' => isset($backup['created_at']) ? $backup['created_at'] : date('Y-m-d H:i:s'),
        'date' => isset($backup['finished_at']) ? strtotime($backup['finished_at']) : (isset($backup['created_at']) ? strtotime($backup['created_at']) : time()),
        'source' => 'saas',
        'files' => $files,
        'components' => $components,
    );
}

// Add local backups not in SaaS
foreach ($local_backups as $local_backup) {
    if (!in_array($local_backup['backup_id'], $saas_backup_ids)) {
        $local_backup['source'] = 'local';
        $all_backups[] = $local_backup;
    }
}

// Sort by date
usort($all_backups, function ($a, $b) {
    return $b['date'] - $a['date'];
});

// Get only last 5 backups for dashboard
$recent_backups = array_slice($all_backups, 0, 5);
?>

<div class="wpv-tab-content" id="wpv-tab-dashboard">
    <!-- Quick Actions Section -->
    <div class="wpv-section">
        <h2><?php _e('Quick Actions', 'wp-vault'); ?></h2>
        
        <div class="wpv-backup-controls">
            <div class="wpv-backup-options">
                <label class="wpv-radio-option">
                    <input type="radio" name="backup_content" value="full" checked>
                    <span><?php _e('Database + Files (WordPress Files)', 'wp-vault'); ?></span>
                </label>
                <label class="wpv-radio-option">
                    <input type="radio" name="backup_content" value="files">
                    <span><?php _e('WordPress Files (Exclude Database)', 'wp-vault'); ?></span>
                </label>
                <label class="wpv-radio-option">
                    <input type="radio" name="backup_content" value="database">
                    <span><?php _e('Only Database', 'wp-vault'); ?></span>
                </label>
            </div>
            
            <div class="wpv-backup-actions">
                <button id="wpv-backup-now-dashboard" class="button button-primary button-large">
                    <span class="dashicons dashicons-cloud-upload"></span>
                    <?php _e('Backup Now', 'wp-vault'); ?>
                </button>
                <a href="<?php echo admin_url('admin.php?page=wp-vault&tab=backups'); ?>" class="button button-secondary button-large">
                    <?php _e('View All Backups', 'wp-vault'); ?>
                </a>
            </div>
        </div>
    </div>
    
    <!-- Recent Backups Section -->
    <div class="wpv-section">
        <h2><?php _e('Recent Backups', 'wp-vault'); ?></h2>
        
        <?php if (empty($recent_backups)): ?>
            <div class="wpv-empty-state">
                <p><?php _e('No backups found. Create a backup using the controls above to get started.', 'wp-vault'); ?></p>
            </div>
        <?php else: ?>
            <p class="wpv-backup-summary">
                <?php printf(__('Showing last %d backup(s) of %d total', 'wp-vault'), count($recent_backups), count($all_backups)); ?>
            </p>
            
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php _e('Backup', 'wp-vault'); ?></th>
                        <th><?php _e('Size', 'wp-vault'); ?></th>
                        <th><?php _e('Type', 'wp-vault'); ?></th>
                        <th><?php _e('Date', 'wp-vault'); ?></th>
                        <th><?php _e('Actions', 'wp-vault'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recent_backups as $backup):
                        $backup_id = $backup['backup_id'];
                        $backup_name = 'Backup ' . substr($backup_id, 0, 8) . '...';
                        $backup_date = strtotime($backup['created_at']);
                        $total_size = $backup['total_size'];
                        if ($total_size === 0 && !empty($backup['files'])) {
                            foreach ($backup['files'] as $file) {
                                $total_size += isset($file['size']) ? $file['size'] : 0;
                            }
                        }
                        ?>
                        <tr>
                            <td>
                                <strong><?php echo esc_html($backup_name); ?></strong>
                                <br>
                                <small style="color:#666;">ID: <?php echo esc_html($backup_id); ?></small>
                                <?php if (isset($backup['source']) && $backup['source'] === 'saas'): ?>
                                    <br><small style="color:#2271b1;">☁️ Cloud Backup</small>
                                <?php elseif (isset($backup['source']) && $backup['source'] === 'local'): ?>
                                    <br><small style="color:#666;">💾 Local Backup</small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <strong><?php echo size_format($total_size); ?></strong>
                                <?php if (isset($backup['status'])): ?>
                                    <br><span class="wpv-status wpv-status-<?php echo esc_attr($backup['status']); ?>" style="font-size:11px; margin-top:3px; display:inline-block;">
                                        <?php echo esc_html(ucfirst($backup['status'])); ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html(ucfirst($backup['backup_type'])); ?></td>
                            <td><?php echo esc_html(date('M j, Y g:i a', $backup_date)); ?></td>
                            <td>
                                <?php
                                $primary_file = !empty($backup['files']) ? $backup['files'][0]['filename'] : 'backup-' . $backup_id . '.tar.gz';
                                $primary_path = $backup_dir . $primary_file;
                                ?>
                                <button class="button button-primary wpv-restore-backup-btn" data-backup-id="<?php echo esc_attr($backup_id); ?>" data-backup-file="<?php echo esc_attr($primary_file); ?>" data-backup-path="<?php echo esc_attr($primary_path); ?>">
                                    <?php _e('Restore', 'wp-vault'); ?>
                                </button>
                                <a href="<?php echo admin_url('admin.php?page=wp-vault&tab=backups'); ?>" class="button">
                                    <?php _e('View', 'wp-vault'); ?>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            
            <p style="margin-top: 16px;">
                <a href="<?php echo admin_url('admin.php?page=wp-vault&tab=backups'); ?>" class="button">
                    <?php _e('View All Backups', 'wp-vault'); ?>
                </a>
            </p>
        <?php endif; ?>
    </div>
</div>
