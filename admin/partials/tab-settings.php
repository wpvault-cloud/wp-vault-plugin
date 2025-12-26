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

// Handle form submissions
if (isset($_POST['wpv_save_settings']) && check_admin_referer('wpv_settings')) {
    update_option('wpv_api_endpoint', sanitize_text_field($_POST['api_endpoint']));
    update_option('wpv_storage_type', sanitize_text_field($_POST['storage_type']));

    if (isset($_POST['compression_mode'])) {
        $compression_mode = sanitize_text_field($_POST['compression_mode']);
        if (in_array($compression_mode, array('fast', 'legacy'))) {
            update_option('wpv_compression_mode', $compression_mode);
        }
    }

    if (isset($_POST['file_split_size'])) {
        $split_size = (int) $_POST['file_split_size'];
        if ($split_size >= 50 && $split_size <= 1000) {
            update_option('wpv_file_split_size', $split_size);
        }
    }

    if ($_POST['storage_type'] === 's3') {
        update_option('wpv_s3_endpoint', sanitize_text_field($_POST['s3_endpoint']));
        update_option('wpv_s3_bucket', sanitize_text_field($_POST['s3_bucket']));
        update_option('wpv_s3_access_key', sanitize_text_field($_POST['s3_access_key']));
        update_option('wpv_s3_secret_key', sanitize_text_field($_POST['s3_secret_key']));
        update_option('wpv_s3_region', sanitize_text_field($_POST['s3_region']));
    }
}

if (isset($_POST['wpv_register_site']) && check_admin_referer('wpv_register')) {
    require_once WP_VAULT_PLUGIN_DIR . 'includes/class-wp-vault-api.php';
    $api = new \WP_Vault\WP_Vault_API();
    $result = $api->register_site(sanitize_email($_POST['admin_email']));

    if ($result['success']) {
        echo '<div class="notice notice-success"><p>' . __('Site registered successfully!', 'wp-vault') . '</p></div>';
    } else {
        echo '<div class="notice notice-error"><p>' . __('Registration failed:', 'wp-vault') . ' ' . esc_html($result['error']) . '</p></div>';
    }
}

if (isset($_POST['wpv_disconnect_site']) && check_admin_referer('wpv_disconnect')) {
    global $wpdb;
    delete_option('wpv_site_id');
    delete_option('wpv_site_token');

    $tables = array(
        $wpdb->prefix . 'wp_vault_jobs',
        $wpdb->prefix . 'wp_vault_job_logs',
        $wpdb->prefix . 'wp_vault_file_index',
        $wpdb->prefix . 'wp_vault_settings',
    );

    foreach ($tables as $table) {
        $wpdb->query("TRUNCATE TABLE {$table}");
        $wpdb->query("DELETE FROM {$table}");
    }

    echo '<div class="notice notice-success"><p>' . __('Site disconnected and local data cleared.', 'wp-vault') . '</p></div>';
}

$primary_storage = get_option('wpv_primary_storage_type', 'gcs');
$storage_type = get_option('wpv_storage_type', $primary_storage);
$registered = (bool) get_option('wpv_site_id');
$compression_mode = get_option('wpv_compression_mode', 'fast');
$file_split_size = get_option('wpv_file_split_size', 200);
?>

<div class="wpv-tab-content" id="wpv-tab-settings">
    <form method="post" action="">
        <?php wp_nonce_field('wpv_settings'); ?>

        <!-- General Settings -->
        <div class="wpv-section">
            <h2><?php _e('General Settings', 'wp-vault'); ?></h2>

            <div class="wpv-settings-group">
                <h3><?php _e('Site Registration', 'wp-vault'); ?></h3>

                <?php if ($registered): ?>
                    <div class="wpv-notice wpv-notice-success">
                        <p>
                            <strong><?php _e('Site Registered', 'wp-vault'); ?></strong><br>
                            <?php _e('Site ID:', 'wp-vault'); ?>
                            <code><?php echo esc_html(get_option('wpv_site_id')); ?></code>
                        </p>
                    </div>
                    <p>
                        <?php wp_nonce_field('wpv_disconnect'); ?>
                        <button type="submit" name="wpv_disconnect_site" class="button">
                            <?php _e('Disconnect Site', 'wp-vault'); ?>
                        </button>
                        <span class="wpv-description">
                            <?php _e('Clears registration and local WP Vault data so you can register again.', 'wp-vault'); ?>
                        </span>
                    </p>
                <?php else: ?>
                    <?php wp_nonce_field('wpv_register'); ?>
                    <table class="form-table">
                        <tr>
                            <th scope="row"><label for="admin_email"><?php _e('Admin Email', 'wp-vault'); ?></label></th>
                            <td>
                                <input type="email" name="admin_email" id="admin_email"
                                    value="<?php echo esc_attr(get_option('admin_email')); ?>" class="regular-text"
                                    required>
                                <p class="description"><?php _e('Your email for WP Vault account', 'wp-vault'); ?></p>
                            </td>
                        </tr>
                    </table>
                    <p class="submit">
                        <button type="submit" name="wpv_register_site" class="button button-primary">
                            <?php _e('Register Site', 'wp-vault'); ?>
                        </button>
                    </p>
                <?php endif; ?>
            </div>

            <div class="wpv-settings-group">
                <h3><?php _e('API Configuration', 'wp-vault'); ?></h3>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="api_endpoint"><?php _e('API Endpoint', 'wp-vault'); ?></label></th>
                        <td>
                            <input type="url" name="api_endpoint" id="api_endpoint"
                                value="<?php echo esc_attr(get_option('wpv_api_endpoint', 'http://host.docker.internal:3000')); ?>"
                                class="regular-text">
                            <p class="description">
                                <strong><?php _e('Docker:', 'wp-vault'); ?></strong>
                                http://host.docker.internal:3000<br>
                                <strong><?php _e('Host:', 'wp-vault'); ?></strong> http://localhost:3000<br>
                                <strong><?php _e('Production:', 'wp-vault'); ?></strong> https://api.wpvault.cloud
                            </p>
                        </td>
                    </tr>
                </table>
            </div>
        </div>

        <!-- Backup Settings -->
        <div class="wpv-section">
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
                            <?php _e('Split backup files into parts when size exceeds this limit. Default: 200MB.', 'wp-vault'); ?>
                        </p>
                    </td>
                </tr>
            </table>

            <h3><?php _e('Temporary File Management', 'wp-vault'); ?></h3>
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

        <p class="submit">
            <input type="submit" name="wpv_save_settings" class="button button-primary"
                value="<?php _e('Save Settings', 'wp-vault'); ?>">
        </p>
    </form>
</div>