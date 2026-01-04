<?php
/**
 * Settings Tab Content
 * 
 * General, Backup, and Storage settings
 *
 * @package WP_Vault
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handle form submissions for settings tab
 */
function wpvault_handle_settings_tab_form()
{
    // Form submission is now handled in class-wp-vault.php handle_settings_form_submission()
    // This function is kept for backward compatibility but form handling happens earlier

    if (isset($_POST['wpv_register_site']) && wp_verify_nonce($_POST['_wpnonce'], 'wpv_register')) {
        require_once WP_VAULT_PLUGIN_DIR . 'includes/class-wp-vault-api.php';
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

    if (isset($_POST['wpv_disconnect_site']) && wp_verify_nonce($_POST['_wpnonce'], 'wpv_disconnect')) {
        global $wpdb;
        delete_option('wpv_site_id');
        delete_option('wpv_site_token');

        $wpvault_tables = array(
            $wpdb->prefix . 'wp_vault_jobs',
            $wpdb->prefix . 'wp_vault_job_logs',
            $wpdb->prefix . 'wp_vault_file_index',
            $wpdb->prefix . 'wp_vault_settings',
        );

        foreach ($wpvault_tables as $wpvault_table) {
            $wpvault_table_escaped = esc_sql($wpvault_table);
            $wpdb->query("TRUNCATE TABLE {$wpvault_table_escaped}");
            $wpdb->query("DELETE FROM {$wpvault_table_escaped}");
        }

        echo '<div class="notice notice-success"><p>' . esc_html__('Site disconnected and local data cleared.', 'wp-vault') . '</p></div>';
    }
}

// Handle form submissions
wpvault_handle_settings_tab_form();

/**
 * Display settings tab content
 */
function wpvault_display_settings_tab()
{
    require_once WP_VAULT_PLUGIN_DIR . 'includes/class-wp-vault-compression-checker.php';
    
    $primary_storage = get_option('wpv_primary_storage_type', 'gcs');
    $storage_type = get_option('wpv_storage_type', $primary_storage);
    $registered = (bool) get_option('wpv_site_id');
    $compression_mode = get_option('wpv_compression_mode', '');
    $file_split_size = get_option('wpv_file_split_size', 200);
    
    // Check compression mode availability
    $fast_availability = \WP_Vault\WP_Vault_Compression_Checker::check_fast_mode();
    $legacy_availability = \WP_Vault\WP_Vault_Compression_Checker::check_legacy_mode();
    
    // Check if activation redirect is needed
    $compression_mode_required = isset($_GET['compression_mode_required']) && $_GET['compression_mode_required'] === 'true';
    ?>

    <div class="wpv-tab-content" id="wpv-tab-settings">
        <?php
        // Show success message if redirected after save
        if (isset($_GET['settings-updated']) && $_GET['settings-updated'] === 'true') {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Settings saved successfully!', 'wp-vault') . '</p></div>';
        }
        
        // Show activation message if compression mode is required
        if ($compression_mode_required && empty($compression_mode)) {
            echo '<div class="notice notice-warning is-dismissible" style="border-left-color: #d63638;">';
            echo '<p><strong>' . esc_html__('Compression Mode Required', 'wp-vault') . '</strong></p>';
            echo '<p>' . esc_html__('Please select a compression mode below to enable backups and restores.', 'wp-vault') . '</p>';
            echo '</div>';
        }
        ?>
        <!-- General Settings -->
        <div class="wpv-section">
            <h2><?php esc_html_e('General Settings', 'wp-vault'); ?></h2>

            <div class="wpv-settings-group">
                <h3><?php esc_html_e('Site Registration', 'wp-vault'); ?></h3>

                <?php if ($registered): ?>
                    <div class="wpv-notice wpv-notice-success">
                        <p>
                            <strong><?php esc_html_e('Site Registered', 'wp-vault'); ?></strong><br>
                            <?php esc_html_e('Site ID:', 'wp-vault'); ?>
                            <code><?php echo esc_html(get_option('wpv_site_id')); ?></code>
                        </p>
                    </div>
                    <form method="post" action="<?php echo esc_url(admin_url('admin.php?page=wp-vault&tab=settings')); ?>">
                        <?php wp_nonce_field('wpv_disconnect'); ?>
                        <p>
                            <button type="submit" name="wpv_disconnect_site" class="button">
                                <?php esc_html_e('Disconnect Site', 'wp-vault'); ?>
                            </button>
                            <span class="wpv-description">
                                <?php esc_html_e('Clears registration and local WP Vault data so you can register again.', 'wp-vault'); ?>
                            </span>
                        </p>
                    </form>
                <?php else: ?>
                    <form method="post" action="<?php echo esc_url(admin_url('admin.php?page=wp-vault&tab=settings')); ?>">
                        <?php wp_nonce_field('wpv_register'); ?>
                        <table class="form-table">
                            <tr>
                                <th scope="row"><label for="admin_email"><?php esc_html_e('Admin Email', 'wp-vault'); ?></label>
                                </th>
                                <td>
                                    <input type="email" name="admin_email" id="admin_email"
                                        value="<?php echo esc_attr(get_option('admin_email')); ?>" class="regular-text"
                                        required>
                                    <p class="description"><?php esc_html_e('Your email for WP Vault account', 'wp-vault'); ?>
                                    </p>
                                </td>
                            </tr>
                        </table>
                        <p class="submit">
                            <button type="submit" name="wpv_register_site" class="button button-primary">
                                <?php esc_html_e('Register Site', 'wp-vault'); ?>
                            </button>
                        </p>
                    </form>
                <?php endif; ?>
            </div>

            <form method="post" action="<?php echo esc_url(admin_url('admin.php?page=wp-vault&tab=settings')); ?>">
                <?php wp_nonce_field('wpv_settings'); ?>

                <div class="wpv-settings-group">
                    <h3><?php esc_html_e('WP Vault Configuration', 'wp-vault'); ?></h3>
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
                                <div class="wpv-notice wpv-notice-info"
                                    style="margin-top: 10px; padding: 12px; background: #f0f6fc; border-left: 4px solid #2271b1; border-radius: 2px;">
                                    <p style="margin: 0;">
                                        <strong><?php esc_html_e('Note:', 'wp-vault'); ?></strong>
                                        <?php esc_html_e('This setting is intended for advanced users who are self-hosting WPVault or using a custom deployment. For standard installations, leave this as the default value.', 'wp-vault'); ?>
                                    </p>
                                </div>
                            </td>
                        </tr>
                    </table>
                </div>

                <!-- Backup Settings -->
                <div class="wpv-section" id="compression-mode-section" <?php echo $compression_mode_required && empty($compression_mode) ? 'style="border: 2px solid #d63638; padding: 15px; border-radius: 4px; background: #fff5f5;"' : ''; ?>>
                    <h2><?php esc_html_e('Backup Configuration', 'wp-vault'); ?></h2>

                    <table class="form-table">
                        <tr>
                            <th scope="row"><?php esc_html_e('Compression Mode', 'wp-vault'); ?></th>
                            <td>
                                <fieldset class="wpv-compression-mode-fieldset">
                                    <legend class="screen-reader-text"><?php esc_html_e('Compression Mode', 'wp-vault'); ?></legend>
                                    
                                    <?php if ($fast_availability['available'] && empty($compression_mode)): ?>
                                        <div class="wpv-notice wpv-notice-info" style="margin-bottom: 15px; padding: 12px; background: #f0f6fc; border-left: 4px solid #2271b1;">
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
                                        <div class="wpv-notice wpv-notice-error" style="margin-top: 15px; padding: 12px; background: #fcf0f1; border-left: 4px solid #d63638;">
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
                                    for="file_split_size"><?php esc_html_e('File Split Size (MB)', 'wp-vault'); ?></label>
                            </th>
                            <td>
                                <input type="number" name="file_split_size" id="file_split_size"
                                    value="<?php echo esc_attr($file_split_size); ?>" min="50" max="1000" step="50"
                                    class="small-text">
                                <p class="description">
                                    <?php esc_html_e('Split backup files into parts when size exceeds this limit. Default: 200MB.', 'wp-vault'); ?>
                                </p>
                            </td>
                        </tr>
                    </table>

                    <h3><?php esc_html_e('Temporary File Management', 'wp-vault'); ?></h3>
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

                <p class="submit">
                    <input type="submit" name="wpv_save_settings" class="button button-primary"
                        value="<?php esc_attr_e('Save Settings', 'wp-vault'); ?>">
                </p>
            </form>
        </div>

    </div>
        <?php
}

// Call the function to display the tab
wpvault_display_settings_tab();
?>