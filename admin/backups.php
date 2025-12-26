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

require_once WP_VAULT_PLUGIN_DIR . 'includes/class-wp-vault-api.php';

/**
 * Display backups page
 */
function wpvault_display_backups_page() {

// Get backups from SaaS API
$api = new \WP_Vault\WP_Vault_API();
$backups_result = $api->get_backups();
$saas_backups = $backups_result['success'] ? $backups_result['data']['backups'] : array();

// Get local backup files (grouped by backup_id) - for legacy/full backups
$backup_dir = WP_CONTENT_DIR . '/wp-vault-backups/';
$local_backups = array();
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

            // Transform components to expected format
            $components = array();
            if (isset($manifest_data['components'])) {
                $manifest_components = $manifest_data['components'];

                // Handle different manifest formats
                if (is_array($manifest_components)) {
                    // Check if it's an associative array (old format: {"themes": ["file1"], "plugins": ["file2"]})
                    if (
                        isset($manifest_components['themes']) || isset($manifest_components['plugins']) ||
                        isset($manifest_components['uploads']) || isset($manifest_components['wp-content']) ||
                        isset($manifest_components['database'])
                    ) {
                        // Old format: transform to new format
                        foreach ($manifest_components as $component_name => $component_files) {
                            if (is_array($component_files)) {
                                $component_archives = array();
                                foreach ($component_files as $file) {
                                    // File might be a string (filename) or array with filename
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
                        // New format: array of component objects
                        foreach ($manifest_components as $comp) {
                            if (is_array($comp) && isset($comp['name'])) {
                                $components[] = $comp;
                            }
                        }
                    }
                }
            }

            // If no components found but we have multiple files, try to infer from filenames
            if (empty($components) && count($actual_files) > 1) {
                $component_map = array(
                    'themes' => array(),
                    'plugins' => array(),
                    'uploads' => array(),
                    'wp-content' => array(),
                    'database' => array()
                );

                foreach ($actual_files as $file) {
                    $filename = $file['filename'];
                    if (preg_match('/^(themes|plugins|uploads|wp-content|database)-/', $filename, $matches)) {
                        $component_name = $matches[1];
                        if (isset($component_map[$component_name])) {
                            $component_map[$component_name][] = $filename;
                        }
                    }
                }

                foreach ($component_map as $component_name => $archives) {
                    if (!empty($archives)) {
                        $components[] = array(
                            'name' => $component_name,
                            'archives' => $archives
                        );
                    }
                }
            }

            $backups_by_id[$backup_id] = array(
                'backup_id' => $backup_id,
                'backup_type' => isset($manifest_data['backup_type']) ? $manifest_data['backup_type'] : 'full',
                'compression_mode' => isset($manifest_data['compression_mode']) ? $manifest_data['compression_mode'] : 'fast',
                'total_size' => $actual_total_size > 0 ? $actual_total_size : (isset($manifest_data['total_size']) ? $manifest_data['total_size'] : 0),
                'created_at' => isset($manifest_data['created_at']) ? $manifest_data['created_at'] : gmdate('Y-m-d H:i:s', filemtime($manifest_file)),
                'date' => filemtime($manifest_file),
                'components' => $components,
                'files' => $actual_files,
                'manifest_file' => basename($manifest_file),
            );
        }
    }

    // Also check for component files that might not be in manifest
    // Look for component files with backup_id pattern: component-backupid-*.tar.gz
    $component_files = glob($backup_dir . '{database,themes,plugins,uploads,wp-content}-*.tar.gz', GLOB_BRACE);
    foreach ($component_files as $file) {
        $filename = basename($file);
        // Extract backup_id from component filename: component-backupid-timestamp.tar.gz
        if (preg_match('/^(database|themes|plugins|uploads|wp-content)-([a-zA-Z0-9_-]+)-/', $filename, $matches)) {
            $component_name = $matches[1];
            $backup_id = $matches[2];

            // Initialize backup entry if not exists
            if (!isset($backups_by_id[$backup_id])) {
                $backups_by_id[$backup_id] = array(
                    'backup_id' => $backup_id,
                    'backup_type' => 'full',
                    'compression_mode' => 'fast',
                    'total_size' => 0,
                    'created_at' => gmdate('Y-m-d H:i:s', filemtime($file)),
                    'date' => filemtime($file),
                    'components' => array(),
                    'files' => array(),
                    'manifest_file' => null,
                );
            }

            // Add component to backup
            $backup_entry = &$backups_by_id[$backup_id];
            $file_size = filesize($file);
            $backup_entry['total_size'] += $file_size;

            // Find or create component entry
            $component_found = false;
            foreach ($backup_entry['components'] as &$comp) {
                if (isset($comp['name']) && $comp['name'] === $component_name) {
                    $comp['archives'][] = $filename;
                    $component_found = true;
                    break;
                }
            }

            if (!$component_found) {
                $backup_entry['components'][] = array(
                    'name' => $component_name,
                    'archives' => array($filename)
                );
            }

            // Add to files array
            $backup_entry['files'][] = array(
                'filename' => $filename,
                'path' => $file,
                'size' => $file_size,
                'component' => $component_name,
            );
        }
    }

    // Also check for legacy single-file backups (without manifest and without components)
    $legacy_files = glob($backup_dir . 'backup-*.tar.gz');
    foreach ($legacy_files as $file) {
        $filename = basename($file);
        // Skip if this is a component file (has component prefix)
        if (preg_match('/^(database|themes|plugins|uploads|wp-content)-/', $filename)) {
            continue; // Already handled above
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
                    'created_at' => gmdate('Y-m-d H:i:s', filemtime($file)),
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
    $local_backups = array_values($backups_by_id);
    usort($local_backups, function ($a, $b) {
        return $b['date'] - $a['date'];
    });
}

// Merge SaaS backups with local backups
// SaaS backups take precedence (they're the source of truth)
$all_backups = array();
$saas_backup_ids = array();

// Add SaaS backups first
foreach ($saas_backups as $backup) {
    $backup_id = $backup['id'];
    $saas_backup_ids[] = $backup_id;

    // Parse components from API response
    $components = array();
    $files = array();

    // Use components if already parsed by API
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

                // Extract filename from object key
                $filename = basename($object_key);
                if (empty($filename)) {
                    $filename = $component_name . '.tar.gz';
                }

                $component_archives[] = $object_key;
                $files[] = array(
                    'filename' => $filename,
                    'path' => $object_key, // GCS object key
                    'size' => $object_size,
                    'component' => $component_name,
                    'is_cloud' => true
                );
            }

            if (!empty($component_archives)) {
                $components[] = array(
                    'name' => $component_name,
                    'archives' => $component_archives,
                    'objects' => $component_objects, // Keep objects for display
                    'total_size' => isset($comp['total_size']) ? $comp['total_size'] : 0
                );
            }
        }
    } elseif (isset($backup['manifest'])) {
        // Fallback: parse manifest if components not already parsed
        $manifest_data = is_string($backup['manifest']) ? json_decode($backup['manifest'], true) : $backup['manifest'];
        if ($manifest_data && isset($manifest_data['components'])) {
            foreach ($manifest_data['components'] as $component_name => $objects) {
                if (is_array($objects) && !empty($objects)) {
                    $component_archives = array();
                    foreach ($objects as $obj) {
                        $object_key = isset($obj['key']) ? $obj['key'] : '';
                        $component_archives[] = $object_key;
                        $files[] = array(
                            'filename' => basename($object_key) ?: $component_name . '.tar.gz',
                            'path' => $object_key,
                            'size' => isset($obj['size']) ? $obj['size'] : 0,
                            'component' => $component_name,
                            'is_cloud' => true
                        );
                    }
                    if (!empty($component_archives)) {
                        $components[] = array(
                            'name' => $component_name,
                            'archives' => $component_archives,
                            'objects' => $objects // Keep objects for display
                        );
                    }
                }
            }
        }

        // Add database if present
        if (isset($manifest_data['db'])) {
            $db_key = isset($manifest_data['db']['key']) ? $manifest_data['db']['key'] : '';
            $db_size = isset($manifest_data['db']['size']) ? $manifest_data['db']['size'] : 0;
            $components[] = array(
                'name' => 'database',
                'archives' => array($db_key),
                'objects' => array($manifest_data['db']) // Keep object for display
            );
            $files[] = array(
                'filename' => basename($db_key) ?: 'database.sql.gz',
                'path' => $db_key,
                'size' => $db_size,
                'component' => 'database',
                'is_cloud' => true
            );
        }
    }

    $all_backups[] = array(
        'backup_id' => $backup_id,
        'backup_type' => isset($backup['backup_type']) ? $backup['backup_type'] : 'full',
        'status' => isset($backup['status']) ? $backup['status'] : 'unknown',
        'total_size' => isset($backup['total_size_bytes']) ? $backup['total_size_bytes'] : 0,
        'created_at' => isset($backup['created_at']) ? $backup['created_at'] : (isset($backup['started_at']) ? $backup['started_at'] : gmdate('Y-m-d H:i:s')),
        'date' => isset($backup['finished_at']) ? strtotime($backup['finished_at']) : (isset($backup['created_at']) ? strtotime($backup['created_at']) : time()),
        'source' => 'saas',
        'files' => $files,
        'components' => $components,
        'snapshot_id' => isset($backup['snapshot_id']) ? $backup['snapshot_id'] : null
    );
}

// Add local backups that aren't in SaaS (legacy backups)
foreach ($local_backups as $local_backup) {
    if (!in_array($local_backup['backup_id'], $saas_backup_ids)) {
        $local_backup['source'] = 'local';
        $all_backups[] = $local_backup;
    }
}

// Sort all backups by date (newest first)
usort($all_backups, function ($a, $b) {
    return $b['date'] - $a['date'];
});

    $backups = $all_backups;
    ?>

    <div class="wrap">
    <h1><?php esc_html_e('WP Vault Backups', 'wp-vault'); ?></h1>

    <?php if (empty($backups)): ?>
        <div class="notice notice-info">
            <p><?php esc_html_e('No backups found. Create a backup from the Dashboard to get started.', 'wp-vault'); ?></p>
        </div>
    <?php else: ?>
        <p><?php
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
                    <th><?php esc_html_e('Backup', 'wp-vault'); ?></th>
                    <th><?php esc_html_e('Size', 'wp-vault'); ?></th>
                    <th><?php esc_html_e('Components', 'wp-vault'); ?></th>
                    <th><?php esc_html_e('Date', 'wp-vault'); ?></th>
                    <th><?php esc_html_e('Actions', 'wp-vault'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($backups as $backup):
                    $backup_id = $backup['backup_id'];
                    // Check if backup has components to display
                    $has_components = false;
                    if (!empty($backup['components'])) {
                        // Check if any component has items
                        foreach ($backup['components'] as $comp) {
                            $comp_objects = isset($comp['objects']) ? $comp['objects'] : array();
                            $comp_archives = isset($comp['archives']) ? $comp['archives'] : array();
                            if (!empty($comp_objects) || !empty($comp_archives)) {
                                $has_components = true;
                                break;
                            }
                        }
                    }
                    // Also check files (for local backups)
                    if (!$has_components && !empty($backup['files']) && count($backup['files']) > 1) {
                        $has_components = true;
                    }
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
                                    title="<?php esc_attr_e('Click to expand/collapse', 'wp-vault'); ?>"></span>
                            <?php endif; ?>
                        </td>
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
                            <strong><?php echo esc_html(size_format($total_size)); ?></strong>
                            <?php if (isset($backup['status'])): ?>
                                <br><span class="wpv-status wpv-status-<?php echo esc_attr($backup['status']); ?>"
                                    style="font-size:11px; margin-top:3px; display:inline-block;">
                                    <?php echo esc_html(ucfirst($backup['status'])); ?>
                                </span>
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
                                <?php esc_html_e('Restore', 'wp-vault'); ?>
                            </button>
                            <button class="button wpv-download-backup-btn" data-backup-id="<?php echo esc_attr($backup_id); ?>"
                                style="margin-left: 5px;">
                                <?php esc_html_e('Download', 'wp-vault'); ?>
                            </button>
                            <button class="button wpv-delete-backup-btn" data-backup-id="<?php echo esc_attr($backup_id); ?>"
                                style="margin-left: 5px;">
                                <?php esc_html_e('Delete', 'wp-vault'); ?>
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

                                        // Show components from manifest
                                        if (!empty($backup['components'])) {
                                            foreach ($backup['components'] as $component) {
                                                $component_name = isset($component['name']) ? $component['name'] : '';
                                                $component_label = isset($component_map[$component_name]) ? $component_map[$component_name] : ucfirst($component_name);
                                                $component_objects = isset($component['objects']) ? $component['objects'] : array();
                                                $component_archives = isset($component['archives']) ? $component['archives'] : array();

                                                // Use objects if available (from SaaS manifest), otherwise use archives (from local manifest)
                                                $items_to_show = !empty($component_objects) ? $component_objects : $component_archives;

                                                foreach ($items_to_show as $item) {
                                                    // Handle both object format (from SaaS) and string format (from local)
                                                    if (is_array($item)) {
                                                        // SaaS format: {key, size, sha256}
                                                        $archive_path = isset($item['key']) ? $item['key'] : '';
                                                        $archive_filename = basename($archive_path) ?: $component_name . '.tar.gz';
                                                        $file_size = isset($item['size']) ? $item['size'] : 0;
                                                        $is_cloud = isset($backup['source']) && $backup['source'] === 'saas';
                                                    } else {
                                                        // Local format: string path
                                                        $archive_path = $item;
                                                        $archive_filename = basename($archive_path);
                                                        $is_cloud = false;
                                                    }

                                                    // Find matching file info
                                                    $file_info = null;
                                                    if (!empty($backup['files'])) {
                                                        foreach ($backup['files'] as $file) {
                                                            if (isset($file['component']) && $file['component'] === $component_name) {
                                                                $file_info = $file;
                                                                break;
                                                            }
                                                            // Also check by filename
                                                            if (
                                                                strpos($file['filename'], $component_name) !== false ||
                                                                strpos($archive_filename, $file['filename']) !== false
                                                            ) {
                                                                $file_info = $file;
                                                                break;
                                                            }
                                                        }
                                                    }

                                                    // If no file info found, check local filesystem (for local backups)
                                                    if (!$file_info && !$is_cloud) {
                                                        $possible_path = $backup_dir . $archive_filename;
                                                        if (file_exists($possible_path)) {
                                                            $file_info = array(
                                                                'filename' => $archive_filename,
                                                                'path' => $possible_path,
                                                                'size' => filesize($possible_path),
                                                            );
                                                        }
                                                    }

                                                    // Use file_info if available, otherwise use item data
                                                    $display_filename = $file_info ? $file_info['filename'] : $archive_filename;
                                                    $display_size = $file_info ? (isset($file_info['size']) ? $file_info['size'] : 0) : $file_size;
                                                    ?>
                                                    <tr>
                                                        <td style="padding:8px;"><strong><?php echo esc_html($component_label); ?></strong></td>
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
                                                                // For local backups, check if file exists
                                                                $local_file_path = isset($file_info['path']) ? $file_info['path'] : $backup_dir . $display_filename;
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
                                        } else {
                                            // Fallback: show files directly
                                            foreach ($backup['files'] as $file):
                                                $file_path = isset($file['path']) ? $file['path'] : $backup_dir . $file['filename'];
                                                ?>
                                                <tr>
                                                    <td style="padding:8px;"><?php esc_html_e('File', 'wp-vault'); ?></td>
                                                    <td style="padding:8px;"><code
                                                            style="font-size:11px;"><?php echo esc_html($file['filename']); ?></code></td>
                                                    <td style="padding:8px;"><?php echo esc_html(size_format($file['size'])); ?></td>
                                                    <td style="padding:8px;">
                                                        <a href="<?php echo esc_url(admin_url('admin-ajax.php?action=wpv_download_backup_file&file=' . urlencode($file['filename']) . '&nonce=' . wp_create_nonce('wp-vault'))); ?>"
                                                            class="button button-small">
                                                            <?php esc_html_e('Download', 'wp-vault'); ?>
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
            <h3 style="margin-top:0;"><?php esc_html_e('Restore Options', 'wp-vault'); ?></h3>
            <p style="color:#666; margin-top:5px;">
                <?php esc_html_e('Select what you want to restore and configure restore options.', 'wp-vault'); ?>
            </p>

            <div style="margin:20px 0;">
                <h4 style="margin-bottom:10px;"><?php esc_html_e('Components to Restore', 'wp-vault'); ?></h4>
                <div style="margin-left:10px;">
                    <label style="display:block; margin:8px 0;">
                        <input type="checkbox" name="restore_component" value="database" checked
                            style="margin-right:8px;">
                        <strong><?php esc_html_e('Database', 'wp-vault'); ?></strong>
                        <span style="color:#666; font-size:12px;"> -
                            <?php esc_html_e('Restore all database tables', 'wp-vault'); ?></span>
                    </label>
                    <label style="display:block; margin:8px 0;">
                        <input type="checkbox" name="restore_component" value="themes" checked
                            style="margin-right:8px;">
                        <strong><?php esc_html_e('Themes', 'wp-vault'); ?></strong>
                        <span style="color:#666; font-size:12px;"> -
                            <?php esc_html_e('Restore theme files', 'wp-vault'); ?></span>
                    </label>
                    <label style="display:block; margin:8px 0;">
                        <input type="checkbox" name="restore_component" value="plugins" checked
                            style="margin-right:8px;">
                        <strong><?php esc_html_e('Plugins', 'wp-vault'); ?></strong>
                        <span style="color:#666; font-size:12px;"> -
                            <?php esc_html_e('Restore plugin files', 'wp-vault'); ?></span>
                    </label>
                    <label style="display:block; margin:8px 0;">
                        <input type="checkbox" name="restore_component" value="uploads" checked
                            style="margin-right:8px;">
                        <strong><?php esc_html_e('Uploads', 'wp-vault'); ?></strong>
                        <span style="color:#666; font-size:12px;"> -
                            <?php esc_html_e('Restore media and uploads', 'wp-vault'); ?></span>
                    </label>
                    <label style="display:block; margin:8px 0;">
                        <input type="checkbox" name="restore_component" value="wp-content" checked
                            style="margin-right:8px;">
                        <strong><?php esc_html_e('WP-Content (Other)', 'wp-vault'); ?></strong>
                        <span style="color:#666; font-size:12px;"> -
                            <?php esc_html_e('Restore other wp-content files', 'wp-vault'); ?></span>
                    </label>
                </div>
            </div>

            <div style="margin:20px 0; padding-top:20px; border-top:1px solid #e2e4e7;">
                <h4 style="margin-bottom:10px;"><?php esc_html_e('Advanced Options', 'wp-vault'); ?></h4>
                <div style="margin-left:10px;">
                    <label style="display:block; margin:8px 0;">
                        <input type="checkbox" name="restore_option" value="reset_directories"
                            style="margin-right:8px;">
                        <strong><?php esc_html_e('Reset Directories', 'wp-vault'); ?></strong>
                        <span style="color:#666; font-size:12px;"> -
                            <?php esc_html_e('Delete existing directories before restore (clean install)', 'wp-vault'); ?></span>
                    </label>
                    <label style="display:block; margin:8px 0;">
                        <input type="checkbox" name="restore_option" value="replace_urls" style="margin-right:8px;">
                        <strong><?php esc_html_e('Replace URLs', 'wp-vault'); ?></strong>
                        <span style="color:#666; font-size:12px;"> -
                            <?php esc_html_e('Replace old URLs with current site URL (for migration)', 'wp-vault'); ?></span>
                    </label>
                    <label style="display:block; margin:8px 0;">
                        <input type="checkbox" name="restore_option" value="deactivate_plugins"
                            style="margin-right:8px;">
                        <strong><?php esc_html_e('Deactivate Plugins', 'wp-vault'); ?></strong>
                        <span style="color:#666; font-size:12px;"> -
                            <?php esc_html_e('Deactivate all plugins before restore (except WP-Vault)', 'wp-vault'); ?></span>
                    </label>
                    <label style="display:block; margin:8px 0;">
                        <input type="checkbox" name="restore_option" value="switch_theme" style="margin-right:8px;">
                        <strong><?php esc_html_e('Switch to Default Theme', 'wp-vault'); ?></strong>
                        <span style="color:#666; font-size:12px;"> -
                            <?php esc_html_e('Switch to default theme before restore', 'wp-vault'); ?></span>
                    </label>
                    <label style="display:block; margin:8px 0;">
                        <input type="checkbox" name="restore_option" value="pre_restore_backup" checked
                            style="margin-right:8px;">
                        <strong><?php esc_html_e('Create Pre-Restore Backup', 'wp-vault'); ?></strong>
                        <span style="color:#666; font-size:12px;"> -
                            <?php esc_html_e('Create backup of current site before restoring (recommended)', 'wp-vault'); ?></span>
                    </label>
                </div>
            </div>

            <div style="margin-top:25px; text-align:right; border-top:1px solid #e2e4e7; padding-top:15px;">
                <button type="button" class="button" id="wpv-cancel-restore-options"
                    style="margin-right:10px;"><?php esc_html_e('Cancel', 'wp-vault'); ?></button>
                <button type="button" class="button button-primary" id="wpv-confirm-restore-options">
                    <?php esc_html_e('Start Restore', 'wp-vault'); ?>
                </button>
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
                    onclick="location.reload()"><?php esc_html_e('Close & Refresh', 'wp-vault'); ?></button>
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
                    alert('<?php echo esc_js(esc_html__('Please select at least one component to restore.', 'wp-vault')); ?>');
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
                $('#wpv-restore-progress-message').text('<?php echo esc_js(esc_html__('Starting restore...', 'wp-vault')); ?>');
                $('#wpv-restore-log-feed').html('<div style="color:#888;"><?php echo esc_js(esc_html__('Waiting for logs...', 'wp-vault')); ?></div>');
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
                        alert('<?php echo esc_js(esc_html__('Restore failed to start:', 'wp-vault')); ?> ' + (response.data.error || '<?php echo esc_js(esc_html__('Unknown error', 'wp-vault')); ?>'));
                        $('#wpv-restore-modal').hide();
                    }
                }).fail(function () {
                    alert('<?php echo esc_js(esc_html__('Network error starting restore', 'wp-vault')); ?>');
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
                if (!confirm('<?php echo esc_js(esc_html__('Are you sure you want to delete this backup and all its components?', 'wp-vault')); ?>')) {
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
                        alert('<?php echo esc_js(esc_html__('Failed to delete backup:', 'wp-vault')); ?> ' + (response.data.error || '<?php echo esc_js(esc_html__('Unknown error', 'wp-vault')); ?>'));
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
                                    $('#wpv-restore-progress-message').text('<?php echo esc_js(esc_html__('Restore completed successfully!', 'wp-vault')); ?>');
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
    <?php
}

// Call the function to display the backups page
wpvault_display_backups_page();
?>