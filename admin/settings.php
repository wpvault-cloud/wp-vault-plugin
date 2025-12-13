<?php
/**
 * WP Vault Settings Page
 *
 * @package WP_Vault
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once WP_VAULT_PLUGIN_DIR . 'includes/class-wp-vault-api.php';

// Handle disconnect site
if (isset($_POST['wpv_disconnect_site']) && check_admin_referer('wpv_disconnect')) {
    global $wpdb;

    // Clear registration options
    delete_option('wpv_site_id');
    delete_option('wpv_site_token');

    // Clear local job/log/settings tables to start fresh
    $tables = array(
        $wpdb->prefix . 'wp_vault_jobs',
        $wpdb->prefix . 'wp_vault_job_logs',
        $wpdb->prefix . 'wp_vault_file_index',
        $wpdb->prefix . 'wp_vault_settings',
    );

    foreach ($tables as $table) {
        // Try TRUNCATE then fall back to DELETE (for engines that disallow TRUNCATE)
        $wpdb->query("TRUNCATE TABLE {$table}");
        $wpdb->query("DELETE FROM {$table}");
    }

    echo '<div class="notice notice-success"><p>' . __('Site disconnected and local data cleared.', 'wp-vault') . '</p></div>';
}

// Handle "Make Primary" action
if (isset($_POST['wpv_make_primary']) && check_admin_referer('wpv_settings')) {
    $primary_storage = sanitize_text_field($_POST['primary_storage_type']);
    update_option('wpv_primary_storage_type', $primary_storage);
    update_option('wpv_storage_type', $primary_storage); // Also update current selection

    echo '<div class="notice notice-success"><p>' .
        sprintf(
            __('Storage type "%s" set as primary. All new backups will use this storage.', 'wp-vault'),
            ucfirst($primary_storage === 'gcs' ? 'WP Vault Cloud' : $primary_storage)
        ) .
        '</p></div>';
}

//Handle form submission
if (isset($_POST['wpv_save_settings']) && check_admin_referer('wpv_settings')) {
    // Save API settings
    update_option('wpv_api_endpoint', sanitize_text_field($_POST['api_endpoint']));

    // Save storage settings
    update_option('wpv_storage_type', sanitize_text_field($_POST['storage_type']));

    // Save compression mode
    if (isset($_POST['compression_mode'])) {
        $compression_mode = sanitize_text_field($_POST['compression_mode']);
        if (in_array($compression_mode, array('fast', 'legacy'))) {
            update_option('wpv_compression_mode', $compression_mode);
        }
    }

    // Save file split size
    if (isset($_POST['file_split_size'])) {
        $split_size = (int) $_POST['file_split_size'];
        if ($split_size >= 50 && $split_size <= 1000) { // Between 50MB and 1GB
            update_option('wpv_file_split_size', $split_size);
        }
    }

    // Storage config (simplified for MVP)
    if ($_POST['storage_type'] === 's3') {
        update_option('wpv_s3_endpoint', sanitize_text_field($_POST['s3_endpoint']));
        update_option('wpv_s3_bucket', sanitize_text_field($_POST['s3_bucket']));
        update_option('wpv_s3_access_key', sanitize_text_field($_POST['s3_access_key']));
        update_option('wpv_s3_secret_key', sanitize_text_field($_POST['s3_secret_key']));
        update_option('wpv_s3_region', sanitize_text_field($_POST['s3_region']));
    }

    echo '<div class="notice notice-success"><p>' . __('Settings saved successfully!', 'wp-vault') . '</p></div>';
}

// Handle site registration
if (isset($_POST['wpv_register_site']) && check_admin_referer('wpv_register')) {
    $api = new \WP_Vault\WP_Vault_API();
    $result = $api->register_site(sanitize_email($_POST['admin_email']));

    if ($result['success']) {
        echo '<div class="notice notice-success"><p>' . __('Site registered successfully!', 'wp-vault') . '</p></div>';
    } else {
        echo '<div class="notice notice-error"><p>' . __('Registration failed:', 'wp-vault') . ' ' . esc_html($result['error']) . '</p></div>';
    }
}

$primary_storage = get_option('wpv_primary_storage_type', 'gcs'); // Primary storage (used for backups)
$storage_type = get_option('wpv_storage_type', $primary_storage); // Current selection (defaults to primary)
$registered = (bool) get_option('wpv_site_id');
$compression_mode = get_option('wpv_compression_mode', 'fast');
$file_split_size = get_option('wpv_file_split_size', 200); // Default 200MB
?>

<div class="wrap">
    <h1><?php _e('WP Vault Settings', 'wp-vault'); ?></h1>

    <h2 class="nav-tab-wrapper">
        <a href="#general" class="nav-tab nav-tab-active"><?php _e('General', 'wp-vault'); ?></a>
        <a href="#backup" class="nav-tab"><?php _e('Backup Settings', 'wp-vault'); ?></a>
        <a href="#storage" class="nav-tab"><?php _e('Storage', 'wp-vault'); ?></a>
    </h2>

    <form method="post" action="">
        <?php wp_nonce_field('wpv_settings'); ?>

        <div id="general" class="wpv-tab-content">
            <h2><?php _e('Site Registration', 'wp-vault'); ?></h2>

            <?php if ($registered): ?>
                <div class="notice notice-success inline">
                    <p>
                        <strong><?php _e('Site Registered', 'wp-vault'); ?></strong><br>
                        <?php _e('Site ID:', 'wp-vault'); ?> <code><?php echo esc_html(get_option('wpv_site_id')); ?></code>
                    </p>
                </div>
                <p class="submit" style="margin-top:10px;">
                    <?php wp_nonce_field('wpv_disconnect'); ?>
                    <button type="submit" name="wpv_disconnect_site" class="button">
                        <?php _e('Disconnect Site', 'wp-vault'); ?>
                    </button>
                    <span class="description"
                        style="margin-left:8px;"><?php _e('Clears registration and local WP Vault data so you can register again.', 'wp-vault'); ?></span>
                </p>
            <?php else: ?>
                <?php wp_nonce_field('wpv_register'); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="admin_email"><?php _e('Admin Email', 'wp-vault'); ?></label></th>
                        <td>
                            <input type="email" name="admin_email" id="admin_email"
                                value="<?php echo esc_attr(get_option('admin_email')); ?>" class="regular-text" required>
                            <p class="description"><?php _e('Your email for WP Vault account', 'wp-vault'); ?></p>
                        </td>
                    </tr>
                </table>
                <p class="submit">
                    <button type="submit" name="wpv_register_site"
                        class="button button-primary"><?php _e('Register Site', 'wp-vault'); ?></button>
                </p>
            <?php endif; ?>

            <h2><?php _e('API Configuration', 'wp-vault'); ?></h2>
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="api_endpoint"><?php _e('API Endpoint', 'wp-vault'); ?></label></th>
                    <td>
                        <input type="url" name="api_endpoint" id="api_endpoint"
                            value="<?php echo esc_attr(get_option('wpv_api_endpoint', 'http://host.docker.internal:3000')); ?>"
                            class="regular-text">
                        <p class="description">
                            <strong><?php _e('Docker:', 'wp-vault'); ?></strong> http://host.docker.internal:3000<br>
                            <strong><?php _e('Host:', 'wp-vault'); ?></strong> http://localhost:3000<br>
                            <strong><?php _e('Production:', 'wp-vault'); ?></strong> https://api.wpvault.io
                        </p>
                    </td>
                </tr>
            </table>
        </div>

        <div id="backup" class="wpv-tab-content" style="display:none;">
            <h2><?php _e('Backup Configuration', 'wp-vault'); ?></h2>
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="compression_mode"><?php _e('Compression Mode', 'wp-vault'); ?></label>
                    </th>
                    <td>
                        <select name="compression_mode" id="compression_mode">
                            <option value="fast" <?php selected($compression_mode, 'fast'); ?>>
                                <?php _e('Fast (tar and gz)', 'wp-vault'); ?>
                            </option>
                            <option value="legacy" <?php selected($compression_mode, 'legacy'); ?>>
                                <?php _e('Legacy (ZIP using PHP native)', 'wp-vault'); ?>
                            </option>
                        </select>
                        <p class="description">
                            <?php _e('Fast mode uses system tar/gzip commands for better performance. Legacy mode uses PHP ZIP for maximum portability on restricted hosting.', 'wp-vault'); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label
                            for="file_split_size"><?php _e('File Split Size (MB)', 'wp-vault'); ?></label></th>
                    <td>
                        <input type="number" name="file_split_size" id="file_split_size"
                            value="<?php echo esc_attr($file_split_size); ?>" min="50" max="1000" step="50"
                            class="small-text">
                        <p class="description">
                            <?php _e('Split backup files into parts when size exceeds this limit. Default: 200MB. Useful for upload/download size limits.', 'wp-vault'); ?>
                        </p>
                    </td>
                </tr>
            </table>

            <h2><?php _e('Temporary File Management', 'wp-vault'); ?></h2>
            <?php
            require_once WP_VAULT_PLUGIN_DIR . 'includes/class-wp-vault-temp-manager.php';
            $temp_manager = new \WP_Vault\WP_Vault_Temp_Manager();
            $total_size = $temp_manager->get_total_size();
            ?>
            <table class="form-table">
                <tr>
                    <th scope="row"><?php _e('Temp Files Size', 'wp-vault'); ?></th>
                    <td>
                        <p><?php echo size_format($total_size); ?></p>
                        <p class="description">
                            <?php _e('Total size of temporary files in wp-content/wp-vault-temp/', 'wp-vault'); ?>
                        </p>
                    </td>
                </tr>
            </table>
            <p>
                <button type="button" id="cleanup-temp-files" class="button">
                    <?php _e('Clean Up Old Temp Files', 'wp-vault'); ?>
                </button>
                <span id="cleanup-result"></span>
            </p>
        </div>

        <div id="storage" class="wpv-tab-content" style="display:none;">
            <h2><?php _e('Storage Configuration', 'wp-vault'); ?></h2>
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="storage_type"><?php _e('Storage Type', 'wp-vault'); ?></label></th>
                    <td>
                        <select name="storage_type" id="storage_type">
                            <option value="gcs" <?php selected($storage_type, 'gcs'); ?>>
                                WP Vault Cloud (Google Cloud
                                Storage)<?php echo $primary_storage === 'gcs' ? ' [PRIMARY]' : ''; ?>
                            </option>
                            <option value="s3" <?php selected($storage_type, 's3'); ?>>
                                Amazon S3 / MinIO / Compatible (BYO
                                Storage)<?php echo $primary_storage === 's3' ? ' [PRIMARY]' : ''; ?>
                            </option>
                            <option value="google-drive" <?php selected($storage_type, 'google-drive'); ?>>
                                Google Drive<?php echo $primary_storage === 'google-drive' ? ' [PRIMARY]' : ''; ?>
                            </option>
                            <option value="ftp" <?php selected($storage_type, 'ftp'); ?>>
                                FTP<?php echo $primary_storage === 'ftp' ? ' [PRIMARY]' : ''; ?>
                            </option>
                            <option value="sftp" <?php selected($storage_type, 'sftp'); ?>>
                                SFTP<?php echo $primary_storage === 'sftp' ? ' [PRIMARY]' : ''; ?>
                            </option>
                        </select>
                        <p class="description">
                            <?php _e('Select storage type to configure. Primary storage (marked with [PRIMARY]) is used for all new backups.', 'wp-vault'); ?>
                        </p>
                    </td>
                </tr>
            </table>

            <!-- GCS Configuration (WP Vault Cloud) -->
            <div id="gcs-config" class="storage-config"
                style="<?php echo $storage_type === 'gcs' ? '' : 'display:none;'; ?>">
                <h3><?php _e('WP Vault Cloud Configuration', 'wp-vault'); ?></h3>
                <div class="notice notice-info inline">
                    <p>
                        <strong><?php _e('WP Vault Cloud', 'wp-vault'); ?></strong><br>
                        <?php _e('Your backups are automatically stored in WP Vault Cloud (Google Cloud Storage).', 'wp-vault'); ?><br>
                        <?php _e('No additional configuration needed - just ensure your site is registered above.', 'wp-vault'); ?>
                    </p>
                </div>
                <?php if ($registered): ?>
                    <p>
                        <button type="button" id="test-gcs-connection"
                            class="button"><?php _e('Test Connection', 'wp-vault'); ?></button>
                        <span id="test-gcs-result"></span>
                    </p>
                    <p>
                        <?php if ($primary_storage !== 'gcs'): ?>
                        <form method="post" action="" style="display:inline;">
                            <?php wp_nonce_field('wpv_settings'); ?>
                            <input type="hidden" name="primary_storage_type" value="gcs">
                            <button type="submit" name="wpv_make_primary" class="button button-primary">
                                <?php _e('Make Primary Storage', 'wp-vault'); ?>
                            </button>
                        </form>
                        <span class="description" style="margin-left:8px;">
                            <?php _e('Set WP Vault Cloud as primary storage for all new backups.', 'wp-vault'); ?>
                        </span>
                    <?php else: ?>
                        <span class="description" style="color:green;">
                            <strong>✓ <?php _e('Primary Storage', 'wp-vault'); ?></strong> -
                            <?php _e('All new backups will be stored in WP Vault Cloud.', 'wp-vault'); ?>
                        </span>
                    <?php endif; ?>
                    </p>
                <?php else: ?>
                    <p class="description">
                        <?php _e('Please register your site first to use WP Vault Cloud.', 'wp-vault'); ?>
                    </p>
                <?php endif; ?>
            </div>

            <!-- S3 Configuration -->
            <div id="s3-config" class="storage-config"
                style="<?php echo $storage_type === 's3' ? '' : 'display:none;'; ?>">
                <h3><?php _e('S3 Configuration', 'wp-vault'); ?></h3>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="s3_endpoint"><?php _e('S3 Endpoint', 'wp-vault'); ?></label></th>
                        <td>
                            <input type="url" name="s3_endpoint" id="s3_endpoint"
                                value="<?php echo esc_attr(get_option('wpv_s3_endpoint', 'http://minio:9000')); ?>"
                                class="regular-text">
                            <p class="description">
                                <strong><?php _e('Docker:', 'wp-vault'); ?></strong> http://minio:9000 (use service
                                name)<br>
                                <strong><?php _e('Host:', 'wp-vault'); ?></strong> http://localhost:9000<br>
                                <strong><?php _e('AWS:', 'wp-vault'); ?></strong> https://s3.amazonaws.com
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="s3_bucket"><?php _e('Bucket Name', 'wp-vault'); ?></label></th>
                        <td>
                            <input type="text" name="s3_bucket" id="s3_bucket"
                                value="<?php echo esc_attr(get_option('wpv_s3_bucket', 'wp-vault-backups')); ?>"
                                class="regular-text">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="s3_access_key"><?php _e('Access Key', 'wp-vault'); ?></label></th>
                        <td>
                            <input type="text" name="s3_access_key" id="s3_access_key"
                                value="<?php echo esc_attr(get_option('wpv_s3_access_key', 'minioadmin')); ?>"
                                class="regular-text">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="s3_secret_key"><?php _e('Secret Key', 'wp-vault'); ?></label></th>
                        <td>
                            <input type="password" name="s3_secret_key" id="s3_secret_key"
                                value="<?php echo esc_attr(get_option('wpv_s3_secret_key', 'minioadmin')); ?>"
                                class="regular-text">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="s3_region"><?php _e('Region', 'wp-vault'); ?></label></th>
                        <td>
                            <input type="text" name="s3_region" id="s3_region"
                                value="<?php echo esc_attr(get_option('wpv_s3_region', 'us-east-1')); ?>"
                                class="regular-text">
                        </td>
                    </tr>
                </table>
                <p>
                    <button type="button" id="test-s3-connection"
                        class="button"><?php _e('Test Connection', 'wp-vault'); ?></button>
                    <span id="test-result"></span>
                </p>
                <p>
                    <?php if ($primary_storage !== 's3'): ?>
                    <form method="post" action="" style="display:inline;">
                        <?php wp_nonce_field('wpv_settings'); ?>
                        <input type="hidden" name="primary_storage_type" value="s3">
                        <button type="submit" name="wpv_make_primary" class="button button-primary">
                            <?php _e('Make Primary Storage', 'wp-vault'); ?>
                        </button>
                    </form>
                    <span class="description" style="margin-left:8px;">
                        <?php _e('Set this S3 storage as primary for all new backups.', 'wp-vault'); ?>
                    </span>
                <?php else: ?>
                    <span class="description" style="color:green;">
                        <strong>✓ <?php _e('Primary Storage', 'wp-vault'); ?></strong> -
                        <?php _e('All new backups will be stored here.', 'wp-vault'); ?>
                    </span>
                <?php endif; ?>
                </p>
            </div>
        </div>

        <p class="submit">
            <input type="submit" name="wpv_save_settings" class="button button-primary"
                value="<?php _e('Save Settings', 'wp-vault'); ?>">
        </p>
    </form>
</div>

<script>
    jQuery(document).ready(function ($) {
        // Tab switching
        $('.nav-tab').on('click', function (e) {
            e.preventDefault();
            var tab = $(this).attr('href');
            $('.nav-tab').removeClass('nav-tab-active');
            $(this).addClass('nav-tab-active');
            $('.wpv-tab-content').hide();
            $(tab).show();
        });

        // Cleanup temp files
        $('#cleanup-temp-files').on('click', function () {
            var $btn = $(this);
            var $result = $('#cleanup-result');

            $btn.prop('disabled', true).text('<?php _e('Cleaning...', 'wp-vault'); ?>');
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
                    $result.html('<span style="color:red">✗ ' + (response.data.error || 'Cleanup failed') + '</span>');
                }
            }).always(function () {
                $btn.prop('disabled', false).text('<?php _e('Clean Up Old Temp Files', 'wp-vault'); ?>');
            });
        });

        // Storage type switching
        $('#storage_type').on('change', function () {
            $('.storage-config').hide();
            $('#' + $(this).val() + '-config').show();
        });

        // Test GCS connection (WP Vault Cloud)
        $('#test-gcs-connection').on('click', function () {
            var $btn = $(this);
            var $result = $('#test-gcs-result');

            $btn.prop('disabled', true).text('<?php _e('Testing...', 'wp-vault'); ?>');
            $result.html('');

            $.post(ajaxurl, {
                action: 'wpv_test_connection',
                storage_type: 'gcs',
                nonce: wpVault.nonce
            }, function (response) {
                if (response.success && response.data.success) {
                    $result.html('<span style="color:green">✓ ' + response.data.message + '</span>');
                    // Auto-select GCS storage type after successful test
                    $('#storage_type').val('gcs').trigger('change');
                    // Auto-save storage type preference
                    $.post(ajaxurl, {
                        action: 'wpv_save_storage_preference',
                        storage_type: 'gcs',
                        nonce: wpVault.nonce
                    }, function (saveResponse) {
                        // If no primary storage is set, auto-set this as primary
                        if (saveResponse.success && saveResponse.data && !saveResponse.data.is_primary) {
                            var primaryStorage = '<?php echo esc_js($primary_storage); ?>';
                            if (!primaryStorage || primaryStorage === '') {
                                // Auto-set as primary via AJAX
                                $.post(ajaxurl, {
                                    action: 'wpv_make_primary_storage',
                                    storage_type: 'gcs',
                                    nonce: wpVault.nonce
                                }, function (primaryResponse) {
                                    if (primaryResponse.success) {
                                        $result.html('<span style="color:green">✓ ' + response.data.message + '</span><br><span style="color:blue; font-size:11px;">WP Vault Cloud has been set as your primary storage.</span>');
                                        // Reload page to show updated UI
                                        setTimeout(function () {
                                            location.reload();
                                        }, 1500);
                                    }
                                });
                            }
                        }
                    });
                } else {
                    $result.html('<span style="color:red">✗ ' + (response.data.error || response.data.message) + '</span>');
                }
            }).always(function () {
                $btn.prop('disabled', false).text('<?php _e('Test Connection', 'wp-vault'); ?>');
            });
        });

        // Test S3 connection
        $('#test-s3-connection').on('click', function () {
            var $btn = $(this);
            var $result = $('#test-result');

            $btn.prop('disabled', true).text('<?php _e('Testing...', 'wp-vault'); ?>');
            $result.html('');

            $.post(ajaxurl, {
                action: 'wpv_test_connection',
                storage_type: 's3',
                config: {
                    endpoint: $('#s3_endpoint').val(),
                    bucket: $('#s3_bucket').val(),
                    access_key: $('#s3_access_key').val(),
                    secret_key: $('#s3_secret_key').val(),
                    region: $('#s3_region').val()
                },
                nonce: wpVault.nonce
            }, function (response) {
                if (response.success && response.data.success) {
                    $result.html('<span style="color:green">✓ ' + response.data.message + '</span>');
                } else {
                    $result.html('<span style="color:red">✗ ' + (response.data.error || response.data.message) + '</span>');
                }
            }).always(function () {
                $btn.prop('disabled', false).text('<?php _e('Test Connection', 'wp-vault'); ?>');
            });
        });
    });
</script>