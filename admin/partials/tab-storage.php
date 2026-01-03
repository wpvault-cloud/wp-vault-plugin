<?php
/**
 * Storage Tab Content
 * 
 * SaaS-connected storage configuration (read-only in plugin)
 *
 * @package WP_Vault
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Display storage tab content
 */
function wpvault_display_storage_tab()
{
    require_once WP_VAULT_PLUGIN_DIR . 'includes/class-wp-vault-api.php';

    $registered = (bool) get_option('wpv_site_id');
    $api_endpoint = get_option('wpv_api_endpoint', 'http://host.docker.internal:3000');
    $api = new \WP_Vault\WP_Vault_API();

    // Fetch storage config from SaaS
    $storage_data = null;
    if ($registered) {
        $storage_result = $api->get_storage_config();
        if ($storage_result['success']) {
            $storage_data = $storage_result;
        }
    }

    $storages = isset($storage_data['storages']) ? $storage_data['storages'] : array();
    $primary_storage = isset($storage_data['primary_storage']) ? $storage_data['primary_storage'] : null;
    ?>

    <div class="wpv-tab-content" id="wpv-tab-storage">
        <h2><?php esc_html_e('Storage Configuration', 'wp-vault'); ?></h2>

        <?php if (!$registered): ?>
            <div class="wpv-notice wpv-notice-warning">
                <p>
                    <strong><?php esc_html_e('Not connected to Vault Cloud', 'wp-vault'); ?></strong><br>
                    <?php esc_html_e('Please connect your site in Settings to use cloud storage.', 'wp-vault'); ?>
                </p>
                <a href="<?php echo esc_url(admin_url('admin.php?page=wp-vault&tab=settings')); ?>"
                    class="button button-primary">
                    <?php esc_html_e('Go to Settings', 'wp-vault'); ?>
                </a>
            </div>
        <?php else: ?>

            <!-- Security Explanation Section -->
            <div class="wpv-section"
                style="background: #f0f9ff; border-left: 4px solid #0ea5e9; padding: 20px; margin: 20px 0;">
                <h3 style="margin-top: 0; color: #0c4a6e;">
                    <?php esc_html_e('Why Configure Storage in the Dashboard?', 'wp-vault'); ?>
                </h3>
                <p style="color: #075985; margin-bottom: 15px;">
                    <strong><?php esc_html_e('Security First:', 'wp-vault'); ?></strong>
                    <?php esc_html_e('WP Vault stores all storage credentials in the secure Vault Cloud dashboard, not in your WordPress database. This protects your credentials even if WordPress is compromised.', 'wp-vault'); ?>
                </p>
                <ul style="color: #075985; margin-left: 20px; margin-bottom: 15px;">
                    <li>
                        <strong><?php esc_html_e('CVE-2023-5576:', 'wp-vault'); ?></strong>
                        <?php esc_html_e('Competitors like WPVivid had a critical vulnerability (CVSS 9.3) where Google Drive API secrets were stored in plaintext in plugin files. WP Vault prevents this by storing credentials server-side.', 'wp-vault'); ?>
                    </li>
                    <li>
                        <strong><?php esc_html_e('OAuth Tokens:', 'wp-vault'); ?></strong>
                        <?php esc_html_e('OAuth tokens for Google Drive, Dropbox, and OneDrive are encrypted and stored in Google Secret Manager, never in WordPress.', 'wp-vault'); ?>
                    </li>
                    <li>
                        <strong><?php esc_html_e('Central Management:', 'wp-vault'); ?></strong>
                        <?php esc_html_e('Manage storage for multiple sites from one dashboard. No need to configure each site individually.', 'wp-vault'); ?>
                    </li>
                    <li>
                        <strong><?php esc_html_e('Audit Logging:', 'wp-vault'); ?></strong>
                        <?php esc_html_e('Full audit trail of storage access and usage in the Vault Cloud dashboard.', 'wp-vault'); ?>
                    </li>
                </ul>
                <p style="margin: 0;">
                    <a href="<?php echo esc_url($api_endpoint); ?>/dashboard/storage" target="_blank"
                        class="button button-primary">
                        <?php esc_html_e('Manage Storage in WP Vault Dashboard →', 'wp-vault'); ?>
                    </a>
                </p>
            </div>

            <!-- Connected Storages Section -->
            <div class="wpv-section">
                <h3><?php esc_html_e('Your Connected Storages', 'wp-vault'); ?></h3>

                <?php if (empty($storages)): ?>
                    <div class="wpv-notice wpv-notice-info">
                        <p>
                            <?php esc_html_e('No storage configured yet. Connect your storage in the WP Vault Dashboard.', 'wp-vault'); ?>
                        </p>
                    </div>
                <?php else: ?>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('Storage Name', 'wp-vault'); ?></th>
                                <th><?php esc_html_e('Type', 'wp-vault'); ?></th>
                                <th><?php esc_html_e('Status', 'wp-vault'); ?></th>
                                <th><?php esc_html_e('Actions', 'wp-vault'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($storages as $storage): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo esc_html($storage['name']); ?></strong>
                                        <?php if (!empty($storage['account_email'])): ?>
                                            <br><small style="color: #666;"><?php echo esc_html($storage['account_email']); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php
                                        $type_labels = array(
                                            'wp_vault_cloud' => 'WP Vault Cloud',
                                            's3' => 'Amazon S3',
                                            'r2' => 'Cloudflare R2',
                                            'wasabi' => 'Wasabi',
                                            'b2' => 'Backblaze B2',
                                            'spaces' => 'DigitalOcean Spaces',
                                            'minio' => 'MinIO',
                                            'google_drive' => 'Google Drive',
                                            'dropbox' => 'Dropbox',
                                            'onedrive' => 'OneDrive',
                                            'ftp' => 'FTP',
                                            'sftp' => 'SFTP',
                                        );
                                        echo esc_html($type_labels[$storage['type']] ?? ucfirst($storage['type']));
                                        ?>
                                    </td>
                                    <td>
                                        <?php if ($storage['is_primary']): ?>
                                            <span class="wpv-status wpv-status-success">✓ <?php esc_html_e('Primary', 'wp-vault'); ?></span>
                                        <?php else: ?>
                                            <span style="color: #666;"><?php esc_html_e('Connected', 'wp-vault'); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <button type="button" class="button button-small test-storage-connection"
                                            data-storage-id="<?php echo esc_attr($storage['id']); ?>">
                                            <?php esc_html_e('Test Connection', 'wp-vault'); ?>
                                        </button>
                                        <?php if (!$storage['is_primary']): ?>
                                            <button type="button" class="button button-small set-primary-storage"
                                                data-storage-id="<?php echo esc_attr($storage['id']); ?>">
                                                <?php esc_html_e('Set as Primary', 'wp-vault'); ?>
                                            </button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <!-- Primary Storage Selection -->
                    <h3 style="margin-top: 30px;"><?php esc_html_e('Select Primary Storage', 'wp-vault'); ?></h3>
                    <p class="description">
                        <?php esc_html_e('Primary storage is used for all new backups. You can change this at any time.', 'wp-vault'); ?>
                    </p>
                    <select id="primary-storage-select" class="regular-text">
                        <option value=""><?php esc_html_e('-- Select Primary Storage --', 'wp-vault'); ?></option>
                        <?php foreach ($storages as $storage): ?>
                            <option value="<?php echo esc_attr($storage['id']); ?>" <?php selected($storage['is_primary'], true); ?>>
                                <?php echo esc_html($storage['name']); ?>
                                <?php if ($storage['type'] === 'wp_vault_cloud' && isset($storage['usage_percent'])): ?>
                                    (<?php echo esc_html($storage['usage_percent']); ?>% used)
                                <?php endif; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <button type="button" id="save-primary-storage" class="button button-primary" style="margin-left: 10px;">
                        <?php esc_html_e('Save Primary Storage', 'wp-vault'); ?>
                    </button>
                    <span id="primary-storage-result" style="margin-left: 10px;"></span>
                <?php endif; ?>
            </div>

            <!-- Local Download Option -->
            <div class="wpv-section">
                <h3><?php esc_html_e('Local Downloads', 'wp-vault'); ?></h3>
                <div class="wpv-notice wpv-notice-info">
                    <p>
                        <strong><?php esc_html_e('Always Available:', 'wp-vault'); ?></strong>
                        <?php esc_html_e('You can always download backups directly to your computer, regardless of cloud storage settings. This works without any Vault Cloud connection.', 'wp-vault'); ?>
                    </p>
                </div>
            </div>

        <?php endif; ?>
    </div>

    <script>
        jQuery(document).ready(function ($) {
            // Test storage connection
            $('.test-storage-connection').on('click', function () {
                var $btn = $(this);
                var storageId = $btn.data('storage-id');
                var $result = $('<span style="margin-left: 10px;"></span>');
                $btn.after($result);

                $btn.prop('disabled', true).text('<?php echo esc_js(esc_html__('Testing...', 'wp-vault')); ?>');
                $result.html('');

                // Note: Test connection would need to be implemented in SaaS API
                // For now, just show a message
                setTimeout(function () {
                    $result.html('<span style="color: green;">✓ Connection test feature coming soon</span>');
                    $btn.prop('disabled', false).text('<?php echo esc_js(esc_html__('Test Connection', 'wp-vault')); ?>');
                }, 1000);
            });

            // Set primary storage
            $('.set-primary-storage').on('click', function () {
                var $btn = $(this);
                var storageId = $btn.data('storage-id');

                if (!confirm('<?php echo esc_js(esc_html__('Set this storage as primary? All new backups will use this storage.', 'wp-vault')); ?>')) {
                    return;
                }

                $btn.prop('disabled', true).text('<?php echo esc_js(esc_html__('Setting...', 'wp-vault')); ?>');

                $.post(ajaxurl, {
                    action: 'wpv_set_primary_storage',
                    storage_id: storageId,
                    nonce: wpVault.nonce
                }, function (response) {
                    if (response.success) {
                        location.reload();
                    } else {
                        alert(response.data.error || '<?php echo esc_js(esc_html__('Failed to set primary storage', 'wp-vault')); ?>');
                        $btn.prop('disabled', false).text('<?php echo esc_js(esc_html__('Set as Primary', 'wp-vault')); ?>');
                    }
                });
            });

            // Save primary storage from dropdown
            $('#save-primary-storage').on('click', function () {
                var $btn = $(this);
                var storageId = $('#primary-storage-select').val();
                var $result = $('#primary-storage-result');

                if (!storageId) {
                    alert('<?php echo esc_js(esc_html__('Please select a storage', 'wp-vault')); ?>');
                    return;
                }

                $btn.prop('disabled', true).text('<?php echo esc_js(esc_html__('Saving...', 'wp-vault')); ?>');
                $result.html('');

                $.post(ajaxurl, {
                    action: 'wpv_set_primary_storage',
                    storage_id: storageId,
                    nonce: wpVault.nonce
                }, function (response) {
                    if (response.success) {
                        $result.html('<span style="color: green;">✓ <?php echo esc_js(esc_html__('Primary storage updated', 'wp-vault')); ?></span>');
                        setTimeout(function () {
                            location.reload();
                        }, 1500);
                    } else {
                        $result.html('<span style="color: red;">✗ ' + (response.data.error || '<?php echo esc_js(esc_html__('Failed to update', 'wp-vault')); ?>') + '</span>');
                        $btn.prop('disabled', false).text('<?php echo esc_js(esc_html__('Save Primary Storage', 'wp-vault')); ?>');
                    }
                });
            });
        });
    </script>
    <?php
}

// Call the function to display the tab
wpvault_display_storage_tab();
?>