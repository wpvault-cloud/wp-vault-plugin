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

/**
 * Handle form submissions for settings page
 */
function wpvault_handle_settings_form()
{
    // Handle disconnect site
    if (isset($_POST['wpv_disconnect_site']) && check_admin_referer('wpv_disconnect')) {
        global $wpdb;

        // Clear registration options
        delete_option('wpv_site_id');
        delete_option('wpv_site_token');

        // Clear local job/log/settings tables to start fresh
        $wpvault_tables = array(
            $wpdb->prefix . 'wp_vault_jobs',
            $wpdb->prefix . 'wp_vault_job_logs',
            $wpdb->prefix . 'wp_vault_file_index',
            $wpdb->prefix . 'wp_vault_settings',
        );

        foreach ($wpvault_tables as $wpvault_table) {
            // Try TRUNCATE then fall back to DELETE (for engines that disallow TRUNCATE)
            $wpvault_table_escaped = esc_sql($wpvault_table);
            $wpdb->query("TRUNCATE TABLE {$wpvault_table_escaped}");
            $wpdb->query("DELETE FROM {$wpvault_table_escaped}");
        }

        echo '<div class="notice notice-success"><p>' . esc_html__('Site disconnected and local data cleared.', 'wp-vault') . '</p></div>';
    }

    // Handle "Make Primary" action
    if (isset($_POST['wpv_make_primary']) && check_admin_referer('wpv_settings')) {
        $wpvault_primary_storage = isset($_POST['primary_storage_type']) ? sanitize_text_field(wp_unslash($_POST['primary_storage_type'])) : '';
        update_option('wpv_primary_storage_type', $wpvault_primary_storage);
        update_option('wpv_storage_type', $wpvault_primary_storage); // Also update current selection

        echo '<div class="notice notice-success"><p>' .
            sprintf(
                /* translators: %s: storage type name */
                esc_html__('Storage type "%s" set as primary. All new backups will use this storage.', 'wp-vault'),
                esc_html(ucfirst($wpvault_primary_storage === 'gcs' ? 'WP Vault Cloud' : $wpvault_primary_storage))
            ) .
            '</p></div>';
    }

    //Handle form submission
    if (isset($_POST['wpv_save_settings']) && check_admin_referer('wpv_settings')) {
        // Save API settings
        update_option('wpv_api_endpoint', isset($_POST['api_endpoint']) ? sanitize_text_field(wp_unslash($_POST['api_endpoint'])) : '');

        // Save storage settings
        update_option('wpv_storage_type', isset($_POST['storage_type']) ? sanitize_text_field(wp_unslash($_POST['storage_type'])) : '');

        // Save compression mode
        if (isset($_POST['compression_mode'])) {
            $wpvault_compression_mode = sanitize_text_field(wp_unslash($_POST['compression_mode']));
            if (in_array($wpvault_compression_mode, array('fast', 'legacy'))) {
                // Validate availability before saving
                require_once WP_VAULT_PLUGIN_DIR . 'includes/class-wp-vault-compression-checker.php';
                $availability = \WP_Vault\WP_Vault_Compression_Checker::get_all_availability();
                
                if ($wpvault_compression_mode === 'fast' && !$availability['fast']['available']) {
                    echo '<div class="notice notice-error"><p>' . esc_html__('Fast compression mode is not available on this system. Please select Legacy mode.', 'wp-vault') . '</p></div>';
                    return;
                }
                
                if ($wpvault_compression_mode === 'legacy' && !$availability['legacy']['available']) {
                    echo '<div class="notice notice-error"><p>' . esc_html__('Legacy compression mode is not available on this system. Please select Fast mode or contact your hosting provider.', 'wp-vault') . '</p></div>';
                    return;
                }
                
                update_option('wpv_compression_mode', $wpvault_compression_mode);
            }
        }

        // Save file split size
        if (isset($_POST['file_split_size'])) {
            $wpvault_split_size = isset($_POST['file_split_size']) ? absint(wp_unslash($_POST['file_split_size'])) : 200;
            if ($wpvault_split_size >= 50 && $wpvault_split_size <= 1000) { // Between 50MB and 1GB
                update_option('wpv_file_split_size', $wpvault_split_size);
            }
        }

        // Storage config (simplified for MVP)
        $wpvault_storage_type = isset($_POST['storage_type']) ? sanitize_text_field(wp_unslash($_POST['storage_type'])) : '';
        if ($wpvault_storage_type === 's3') {
            update_option('wpv_s3_endpoint', isset($_POST['s3_endpoint']) ? sanitize_text_field(wp_unslash($_POST['s3_endpoint'])) : '');
            update_option('wpv_s3_bucket', isset($_POST['s3_bucket']) ? sanitize_text_field(wp_unslash($_POST['s3_bucket'])) : '');
            update_option('wpv_s3_access_key', isset($_POST['s3_access_key']) ? sanitize_text_field(wp_unslash($_POST['s3_access_key'])) : '');
            update_option('wpv_s3_secret_key', isset($_POST['s3_secret_key']) ? sanitize_text_field(wp_unslash($_POST['s3_secret_key'])) : '');
            update_option('wpv_s3_region', isset($_POST['s3_region']) ? sanitize_text_field(wp_unslash($_POST['s3_region'])) : '');
        }

        echo '<div class="notice notice-success"><p>' . esc_html__('Settings saved successfully!', 'wp-vault') . '</p></div>';
    }

    // Handle site registration
    if (isset($_POST['wpv_register_site']) && check_admin_referer('wpv_register')) {
        $wpvault_api = new \WP_Vault\WP_Vault_API();
        $wpvault_admin_email = isset($_POST['admin_email']) ? sanitize_email(wp_unslash($_POST['admin_email'])) : '';
        if (empty($wpvault_admin_email)) {
            echo '<div class="notice notice-error"><p>' . esc_html__('Email is required', 'wp-vault') . '</p></div>';
            return;
        }
        $wpvault_result = $wpvault_api->register_site($wpvault_admin_email);

        if ($wpvault_result['success']) {
            echo '<div class="notice notice-success"><p>' . esc_html__('Site registered successfully!', 'wp-vault') . '</p></div>';
        } else {
            echo '<div class="notice notice-error"><p>' . esc_html__('Registration failed:', 'wp-vault') . ' ' . esc_html($wpvault_result['error']) . '</p></div>';
        }
    }
}

// Handle form submissions
wpvault_handle_settings_form();

/**
 * Display settings page
 */
function wpvault_display_settings_page()
{
    require_once WP_VAULT_PLUGIN_DIR . 'includes/class-wp-vault-compression-checker.php';
    
    $primary_storage = get_option('wpv_primary_storage_type', 'gcs'); // Primary storage (used for backups)
    $storage_type = get_option('wpv_storage_type', $primary_storage); // Current selection (defaults to primary)
    $registered = (bool) get_option('wpv_site_id');
    $compression_mode = get_option('wpv_compression_mode', '');
    $file_split_size = get_option('wpv_file_split_size', 200); // Default 200MB
    
    // Check compression mode availability
    $fast_availability = \WP_Vault\WP_Vault_Compression_Checker::check_fast_mode();
    $legacy_availability = \WP_Vault\WP_Vault_Compression_Checker::check_legacy_mode();
    
    // Check if activation redirect is needed
    $compression_mode_required = isset($_GET['compression_mode_required']) && $_GET['compression_mode_required'] === 'true';
    ?>

    <div class="wrap wpv-settings-standalone">
        <div class="wpv-header">
            <div class="wpv-header-left">
                <h1 class="wpv-page-title">
                    <img src="<?php echo esc_url(WP_VAULT_PLUGIN_URL . 'assets/images/logo.svg'); ?>" alt="WP Vault"
                        class="wpv-logo" style="width: 32px; height: 32px; margin-right: 10px; vertical-align: middle;" />
                    <?php esc_html_e('WP Vault Settings', 'wp-vault'); ?>
                </h1>
            </div>
            <div class="wpv-header-right">
                <a href="<?php echo esc_url(admin_url('admin.php?page=wp-vault&tab=settings')); ?>" class="button">
                    <?php esc_html_e('View in Dashboard', 'wp-vault'); ?>
                </a>
            </div>
        </div>

        <h2 class="nav-tab-wrapper">
            <a href="#general" class="nav-tab nav-tab-active"><?php esc_html_e('General', 'wp-vault'); ?></a>
            <a href="#backup" class="nav-tab"><?php esc_html_e('Backup Settings', 'wp-vault'); ?></a>
            <a href="#storage" class="nav-tab"><?php esc_html_e('Storage', 'wp-vault'); ?></a>
        </h2>

        <form method="post" action="">
            <?php wp_nonce_field('wpv_settings'); ?>

            <div id="general" class="wpv-tab-content">
                <h2><?php esc_html_e('Site Registration', 'wp-vault'); ?></h2>

                <?php if ($registered): ?>
                    <div class="notice notice-success inline">
                        <p>
                            <strong><?php esc_html_e('Site Registered', 'wp-vault'); ?></strong><br>
                            <?php esc_html_e('Site ID:', 'wp-vault'); ?>
                            <code><?php echo esc_html(get_option('wpv_site_id')); ?></code>
                        </p>
                    </div>
                    <p class="submit" style="margin-top:10px;">
                        <?php wp_nonce_field('wpv_disconnect'); ?>
                        <button type="submit" name="wpv_disconnect_site" class="button">
                            <?php esc_html_e('Disconnect Site', 'wp-vault'); ?>
                        </button>
                        <span class="description"
                            style="margin-left:8px;"><?php esc_html_e('Clears registration and local WP Vault data so you can register again.', 'wp-vault'); ?></span>
                    </p>
                <?php else: ?>
                    <?php wp_nonce_field('wpv_register'); ?>
                    <table class="form-table">
                        <tr>
                            <th scope="row"><label for="admin_email"><?php esc_html_e('Admin Email', 'wp-vault'); ?></label>
                            </th>
                            <td>
                                <input type="email" name="admin_email" id="admin_email"
                                    value="<?php echo esc_attr(get_option('admin_email')); ?>" class="regular-text" required>
                                <p class="description"><?php esc_html_e('Your email for WP Vault account', 'wp-vault'); ?></p>
                            </td>
                        </tr>
                    </table>
                    <p class="submit">
                        <button type="submit" name="wpv_register_site"
                            class="button button-primary"><?php esc_html_e('Register Site', 'wp-vault'); ?></button>
                    </p>
                <?php endif; ?>

                <h2><?php esc_html_e('API Configuration', 'wp-vault'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="api_endpoint"><?php esc_html_e('Cloud URL', 'wp-vault'); ?></label>
                        </th>
                        <td>
                            <input type="url" name="api_endpoint" id="api_endpoint"
                                value="<?php echo esc_attr(get_option('wpv_api_endpoint', 'https://wpvault.cloud')); ?>"
                                class="regular-text" placeholder="https://wpvault.cloud">
                            <p class="description">
                                <?php esc_html_e('The default Cloud URL connects to WPVault Cloud services. Only change this endpoint if you are using a custom version of WPVault or hosting the service yourself.', 'wp-vault'); ?>
                            </p>
                        </td>
                    </tr>
                </table>
            </div>

            <div id="backup" class="wpv-tab-content" style="display:none;">
                <?php if ($compression_mode_required && empty($compression_mode)): ?>
                    <div class="notice notice-warning inline" style="border-left-color: #d63638;">
                        <p><strong><?php esc_html_e('Compression Mode Required', 'wp-vault'); ?></strong></p>
                        <p><?php esc_html_e('Please select a compression mode below to enable backups and restores.', 'wp-vault'); ?></p>
                    </div>
                <?php endif; ?>
                
                <h2><?php esc_html_e('Backup Configuration', 'wp-vault'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e('Compression Mode', 'wp-vault'); ?></th>
                        <td>
                            <fieldset class="wpv-compression-mode-fieldset">
                                <legend class="screen-reader-text"><?php esc_html_e('Compression Mode', 'wp-vault'); ?></legend>
                                
                                <?php if ($fast_availability['available'] && empty($compression_mode)): ?>
                                    <div class="notice notice-info inline" style="margin-bottom: 15px;">
                                        <p style="margin: 0;">
                                            <strong><?php esc_html_e('Recommended:', 'wp-vault'); ?></strong>
                                            <?php esc_html_e('Fast mode is available on your system and provides better performance. We recommend selecting it.', 'wp-vault'); ?>
                                        </p>
                                    </div>
                                <?php endif; ?>
                                
                                <div class="wpv-compression-mode-option" style="margin-bottom: 20px; padding: 15px; border: 1px solid #c3c4c7; border-radius: 4px; background: #fff;">
                                    <label style="display: flex; align-items: flex-start; cursor: <?php echo $fast_availability['available'] ? 'pointer' : 'not-allowed'; ?>;">
                                        <input type="radio" name="compression_mode" value="fast" 
                                            <?php checked($compression_mode, 'fast'); ?>
                                            <?php disabled(!$fast_availability['available']); ?>
                                            style="margin-right: 10px; margin-top: 3px;">
                                        <div style="flex: 1;">
                                            <div style="display: flex; align-items: center; margin-bottom: 5px;">
                                                <strong><?php esc_html_e('Fast (tar and gz)', 'wp-vault'); ?></strong>
                                                <?php if ($fast_availability['available']): ?>
                                                    <span style="color: #00a32a; margin-left: 8px;" title="<?php esc_attr_e('Available', 'wp-vault'); ?>">✓</span>
                                                <?php else: ?>
                                                    <span style="color: #d63638; margin-left: 8px;" title="<?php esc_attr_e('Not Available', 'wp-vault'); ?>">✗</span>
                                                <?php endif; ?>
                                            </div>
                                            <p class="description" style="margin: 5px 0;">
                                                <?php esc_html_e('Uses system tar/gzip commands for better performance and lower memory usage.', 'wp-vault'); ?>
                                            </p>
                                            <div style="margin-top: 8px; font-size: 12px;">
                                                <strong><?php esc_html_e('Requirements:', 'wp-vault'); ?></strong>
                                                <ul style="margin: 5px 0 0 20px; list-style: disc;">
                                                    <?php foreach ($fast_availability['requirements'] as $req): ?>
                                                        <li style="color: <?php echo $req['available'] ? '#00a32a' : '#d63638'; ?>;">
                                                            <?php echo esc_html($req['name']); ?>: 
                                                            <span><?php echo esc_html($req['reason']); ?></span>
                                                        </li>
                                                    <?php endforeach; ?>
                                                </ul>
                                            </div>
                                        </div>
                                    </label>
                                </div>
                                
                                <div class="wpv-compression-mode-option" style="margin-bottom: 20px; padding: 15px; border: 1px solid #c3c4c7; border-radius: 4px; background: #fff;">
                                    <label style="display: flex; align-items: flex-start; cursor: <?php echo $legacy_availability['available'] ? 'pointer' : 'not-allowed'; ?>;">
                                        <input type="radio" name="compression_mode" value="legacy" 
                                            <?php checked($compression_mode, 'legacy'); ?>
                                            <?php disabled(!$legacy_availability['available']); ?>
                                            style="margin-right: 10px; margin-top: 3px;">
                                        <div style="flex: 1;">
                                            <div style="display: flex; align-items: center; margin-bottom: 5px;">
                                                <strong><?php esc_html_e('Legacy (ZIP using PHP native)', 'wp-vault'); ?></strong>
                                                <?php if ($legacy_availability['available']): ?>
                                                    <span style="color: #00a32a; margin-left: 8px;" title="<?php esc_attr_e('Available', 'wp-vault'); ?>">✓</span>
                                                <?php else: ?>
                                                    <span style="color: #d63638; margin-left: 8px;" title="<?php esc_attr_e('Not Available', 'wp-vault'); ?>">✗</span>
                                                <?php endif; ?>
                                            </div>
                                            <p class="description" style="margin: 5px 0;">
                                                <?php esc_html_e('Uses PHP ZIP extension for maximum portability on restricted hosting environments.', 'wp-vault'); ?>
                                            </p>
                                            <div style="margin-top: 8px; font-size: 12px;">
                                                <strong><?php esc_html_e('Requirements:', 'wp-vault'); ?></strong>
                                                <ul style="margin: 5px 0 0 20px; list-style: disc;">
                                                    <?php foreach ($legacy_availability['requirements'] as $req): ?>
                                                        <li style="color: <?php echo $req['available'] ? '#00a32a' : '#d63638'; ?>;">
                                                            <?php echo esc_html($req['name']); ?>: 
                                                            <span><?php echo esc_html($req['reason']); ?></span>
                                                        </li>
                                                    <?php endforeach; ?>
                                                </ul>
                                            </div>
                                        </div>
                                    </label>
                                </div>
                                
                                <?php if (!$fast_availability['available'] && !$legacy_availability['available']): ?>
                                    <div class="notice notice-error inline" style="margin-top: 15px;">
                                        <p style="margin: 0;">
                                            <strong><?php esc_html_e('No Compression Mode Available', 'wp-vault'); ?></strong><br>
                                            <?php esc_html_e('Neither compression mode is available on your system. Please contact your hosting provider to enable either tar/gzip commands or PHP ZipArchive extension.', 'wp-vault'); ?>
                            </p>
                                    </div>
                                <?php endif; ?>
                            </fieldset>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label
                                for="file_split_size"><?php esc_html_e('File Split Size (MB)', 'wp-vault'); ?></label></th>
                        <td>
                            <input type="number" name="file_split_size" id="file_split_size"
                                value="<?php echo esc_attr($file_split_size); ?>" min="50" max="1000" step="50"
                                class="small-text">
                            <p class="description">
                                <?php esc_html_e('Split backup files into parts when size exceeds this limit. Default: 200MB. Useful for upload/download size limits.', 'wp-vault'); ?>
                            </p>
                        </td>
                    </tr>
                </table>

                <h2><?php esc_html_e('Temporary File Management', 'wp-vault'); ?></h2>
                <?php
                require_once WP_VAULT_PLUGIN_DIR . 'includes/class-wp-vault-temp-manager.php';
                $temp_manager = new \WP_Vault\WP_Vault_Temp_Manager();
                $total_size = $temp_manager->get_total_size();
                ?>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e('Temp Files Size', 'wp-vault'); ?></th>
                        <td>
                            <p><?php echo esc_html(size_format($total_size)); ?></p>
                            <p class="description">
                                <?php esc_html_e('Total size of temporary files in wp-content/wp-vault-temp/', 'wp-vault'); ?>
                            </p>
                        </td>
                    </tr>
                </table>
                <p>
                    <button type="button" id="cleanup-temp-files" class="button">
                        <?php esc_html_e('Clean Up Old Temp Files', 'wp-vault'); ?>
                    </button>
                    <span id="cleanup-result"></span>
                </p>
            </div>

            <?php include WP_VAULT_PLUGIN_DIR . 'admin/settings-storage.php'; ?>

            <p class="submit">
                <input type="submit" name="wpv_save_settings" class="button button-primary"
                    value="<?php esc_attr_e('Save Settings', 'wp-vault'); ?>">
            </p>
        </form>
    </div>

    <script>     jQuery(document).ready(function ($) {         // Tab switching         $('.nav-tab').on('click', function (e) {             e.preventDefault();             var tab = $(this).attr('href');             $('.nav-tab').removeClass('nav-tab-active');             $(this).addClass('nav-tab-active');             $('.wpv-tab-content').hide();             $(tab).show();         });
            // Cleanup temp files         $('#cleanup-temp-files').on('click', function () {             var $btn = $(this);             var $result = $('#cleanup-result');
            $btn.prop('disabled', true).text('<?php echo esc_js(esc_html__('Cleaning...', 'wp-vault')); ?>'); $result.html('');
            $.post(ajaxurl, { action: 'wpv_cleanup_temp_files', nonce: wpVault.nonce }, function (response) { if (response.success) { $result.html('<span style="color:green">✓ ' + response.data.message + '</span>'); setTimeout(function () { location.reload(); }, 1500); } else { $result.html('<span style="color:red">✗ ' + (response.data.error || 'Cleanup failed') + '</span>'); } }).always(function () { $btn.prop('disabled', false).text('<?php echo esc_js(esc_html__('Clean Up Old Temp Files', 'wp-vault')); ?>'); });
        });     });
    </script>
    <?php
}

// Call the function to display the settings page
wpvault_display_settings_page();
?>