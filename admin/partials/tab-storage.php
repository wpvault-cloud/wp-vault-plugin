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
    <h2><?php _e('Storage Configuration', 'wp-vault'); ?></h2>

    <?php if (!$registered): ?>
        <div class="wpv-notice wpv-notice-warning">
            <p>
                <strong><?php _e('Site Not Registered', 'wp-vault'); ?></strong><br>
                <?php _e('Please register your site in Settings to use cloud storage.', 'wp-vault'); ?>
            </p>
            <a href="<?php echo admin_url('admin.php?page=wp-vault&tab=settings'); ?>" class="button button-primary">
                <?php _e('Go to Settings', 'wp-vault'); ?>
            </a>
        </div>
    <?php else: ?>

        <!-- Security Explanation Section -->
        <div class="wpv-section"
            style="background: #f0f9ff; border-left: 4px solid #0ea5e9; padding: 20px; margin: 20px 0;">
            <h3 style="margin-top: 0; color: #0c4a6e;">
                <?php _e('Why Configure Storage in the Dashboard?', 'wp-vault'); ?>
            </h3>
            <p style="color: #075985; margin-bottom: 15px;">
                <strong><?php _e('Security First:', 'wp-vault'); ?></strong>
                <?php _e('WP Vault stores all storage credentials in the secure SaaS dashboard, not in your WordPress database. This protects your credentials even if WordPress is compromised.', 'wp-vault'); ?>
            </p>
            <ul style="color: #075985; margin-left: 20px; margin-bottom: 15px;">
                <li>
                    <strong><?php _e('CVE-2023-5576:', 'wp-vault'); ?></strong>
                    <?php _e('Competitors like WPVivid had a critical vulnerability (CVSS 9.3) where Google Drive API secrets were stored in plaintext in plugin files. WP Vault prevents this by storing credentials server-side.', 'wp-vault'); ?>
                </li>
                <li>
                    <strong><?php _e('OAuth Tokens:', 'wp-vault'); ?></strong>
                    <?php _e('OAuth tokens for Google Drive, Dropbox, and OneDrive are encrypted and stored in Google Secret Manager, never in WordPress.', 'wp-vault'); ?>
                </li>
                <li>
                    <strong><?php _e('Central Management:', 'wp-vault'); ?></strong>
                    <?php _e('Manage storage for multiple sites from one dashboard. No need to configure each site individually.', 'wp-vault'); ?>
                </li>
                <li>
                    <strong><?php _e('Audit Logging:', 'wp-vault'); ?></strong>
                    <?php _e('Full audit trail of storage access and usage in the SaaS dashboard.', 'wp-vault'); ?>
                </li>
            </ul>
            <p style="margin: 0;">
                <a href="<?php echo esc_url($api_endpoint); ?>/dashboard/storage" target="_blank"
                    class="button button-primary">
                    <?php _e('Manage Storage in WP Vault Dashboard →', 'wp-vault'); ?>
                </a>
            </p>
        </div>

        <!-- Connected Storages Section -->
        <div class="wpv-section">
            <h3><?php _e('Your Connected Storages', 'wp-vault'); ?></h3>

            <?php if (empty($storages)): ?>
                <div class="wpv-notice wpv-notice-info">
                    <p>
                        <?php _e('No storage configured yet. Connect your storage in the WP Vault Dashboard.', 'wp-vault'); ?>
                    </p>
                </div>
            <?php else: ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php _e('Storage Name', 'wp-vault'); ?></th>
                            <th><?php _e('Type', 'wp-vault'); ?></th>
                            <th><?php _e('Status', 'wp-vault'); ?></th>
                            <th><?php _e('Actions', 'wp-vault'); ?></th>
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
                                        <span class="wpv-status wpv-status-success">✓ <?php _e('Primary', 'wp-vault'); ?></span>
                                    <?php else: ?>
                                        <span style="color: #666;"><?php _e('Connected', 'wp-vault'); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <button type="button" class="button button-small test-storage-connection"
                                        data-storage-id="<?php echo esc_attr($storage['id']); ?>">
                                        <?php _e('Test Connection', 'wp-vault'); ?>
                                    </button>
                                    <?php if (!$storage['is_primary']): ?>
                                        <button type="button" class="button button-small set-primary-storage"
                                            data-storage-id="<?php echo esc_attr($storage['id']); ?>">
                                            <?php _e('Set as Primary', 'wp-vault'); ?>
                                        </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <!-- Primary Storage Selection -->
                <h3 style="margin-top: 30px;"><?php _e('Select Primary Storage', 'wp-vault'); ?></h3>
                <p class="description">
                    <?php _e('Primary storage is used for all new backups. You can change this at any time.', 'wp-vault'); ?>
                </p>
                <select id="primary-storage-select" class="regular-text">
                    <option value=""><?php _e('-- Select Primary Storage --', 'wp-vault'); ?></option>
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
                    <?php _e('Save Primary Storage', 'wp-vault'); ?>
                </button>
                <span id="primary-storage-result" style="margin-left: 10px;"></span>
            <?php endif; ?>
        </div>

        <!-- Local Download Option -->
        <div class="wpv-section">
            <h3><?php _e('Local Downloads', 'wp-vault'); ?></h3>
            <div class="wpv-notice wpv-notice-info">
                <p>
                    <strong><?php _e('Always Available:', 'wp-vault'); ?></strong>
                    <?php _e('You can always download backups directly to your computer, regardless of cloud storage settings. This works without any SaaS connection.', 'wp-vault'); ?>
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

            $btn.prop('disabled', true).text('<?php _e('Testing...', 'wp-vault'); ?>');
            $result.html('');

            // Note: Test connection would need to be implemented in SaaS API
            // For now, just show a message
            setTimeout(function () {
                $result.html('<span style="color: green;">✓ Connection test feature coming soon</span>');
                $btn.prop('disabled', false).text('<?php _e('Test Connection', 'wp-vault'); ?>');
            }, 1000);
        });

        // Set primary storage
        $('.set-primary-storage').on('click', function () {
            var $btn = $(this);
            var storageId = $btn.data('storage-id');

            if (!confirm('<?php _e('Set this storage as primary? All new backups will use this storage.', 'wp-vault'); ?>')) {
                return;
            }

            $btn.prop('disabled', true).text('<?php _e('Setting...', 'wp-vault'); ?>');

            $.post(ajaxurl, {
                action: 'wpv_set_primary_storage',
                storage_id: storageId,
                nonce: wpVault.nonce
            }, function (response) {
                if (response.success) {
                    location.reload();
                } else {
                    alert(response.data.error || '<?php _e('Failed to set primary storage', 'wp-vault'); ?>');
                    $btn.prop('disabled', false).text('<?php _e('Set as Primary', 'wp-vault'); ?>');
                }
            });
        });

        // Save primary storage from dropdown
        $('#save-primary-storage').on('click', function () {
            var $btn = $(this);
            var storageId = $('#primary-storage-select').val();
            var $result = $('#primary-storage-result');

            if (!storageId) {
                alert('<?php _e('Please select a storage', 'wp-vault'); ?>');
                return;
            }

            $btn.prop('disabled', true).text('<?php _e('Saving...', 'wp-vault'); ?>');
            $result.html('');

            $.post(ajaxurl, {
                action: 'wpv_set_primary_storage',
                storage_id: storageId,
                nonce: wpVault.nonce
            }, function (response) {
                if (response.success) {
                    $result.html('<span style="color: green;">✓ <?php _e('Primary storage updated', 'wp-vault'); ?></span>');
                    setTimeout(function () {
                        location.reload();
                    }, 1500);
                } else {
                    $result.html('<span style="color: red;">✗ ' + (response.data.error || '<?php _e('Failed to update', 'wp-vault'); ?>') + '</span>');
                    $btn.prop('disabled', false).text('<?php _e('Save Primary Storage', 'wp-vault'); ?>');
                }
            });
        });
    });
</script>