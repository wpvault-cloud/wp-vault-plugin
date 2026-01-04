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
 * Get storage provider information
 */
function wpvault_get_storage_provider_info($type)
{
    $providers = array(
        'wp_vault_cloud' => array(
            'name' => __('WP Vault Cloud (GCS)', 'wp-vault'),
            'icon' => 'dashicons-cloud',
            'description' => __('Auto-configured, zero setup. Managed Google Cloud Storage with 3GB free.', 'wp-vault'),
            'config_method' => __('Auto-configured, zero setup', 'wp-vault'),
            'category' => 'managed',
        ),
        'google_drive' => array(
            'name' => __('Google Drive', 'wp-vault'),
            'icon' => 'dashicons-googleplus',
            'description' => __('OAuth-based storage with secure token management.', 'wp-vault'),
            'config_method' => __('OAuth handled by SaaS', 'wp-vault'),
            'category' => 'oauth',
        ),
        'dropbox' => array(
            'name' => __('Dropbox', 'wp-vault'),
            'icon' => 'dashicons-networking',
            'description' => __('OAuth-based storage with secure token management.', 'wp-vault'),
            'config_method' => __('OAuth handled by SaaS', 'wp-vault'),
            'category' => 'oauth',
        ),
        'onedrive' => array(
            'name' => __('OneDrive', 'wp-vault'),
            'icon' => 'dashicons-microsoft-alt',
            'description' => __('OAuth-based storage with secure token management.', 'wp-vault'),
            'config_method' => __('OAuth handled by SaaS', 'wp-vault'),
            'category' => 'oauth',
        ),
        's3' => array(
            'name' => __('Amazon S3', 'wp-vault'),
            'icon' => 'dashicons-amazon',
            'description' => __('Industry-standard object storage with global availability.', 'wp-vault'),
            'config_method' => __('API keys in Secret Manager', 'wp-vault'),
            'category' => 's3',
        ),
        'r2' => array(
            'name' => __('Cloudflare R2', 'wp-vault'),
            'icon' => 'dashicons-shield-alt',
            'description' => __('S3-compatible storage with zero egress fees.', 'wp-vault'),
            'config_method' => __('API keys in Secret Manager', 'wp-vault'),
            'category' => 's3',
        ),
        's3-compatible' => array(
            'name' => __('S3-Compatible', 'wp-vault'),
            'icon' => 'dashicons-admin-network',
            'description' => __('Any S3-compatible storage service (Wasabi, Backblaze B2, DigitalOcean Spaces, MinIO, etc.).', 'wp-vault'),
            'config_method' => __('API keys in Secret Manager', 'wp-vault'),
            'category' => 's3',
        ),
    );

    return isset($providers[$type]) ? $providers[$type] : array(
        'name' => ucfirst(str_replace('_', ' ', $type)),
        'icon' => 'dashicons-admin-settings',
        'description' => '',
        'config_method' => '',
        'category' => 'other',
    );
}

/**
 * Display storage tab content
 */
function wpvault_display_storage_tab()
{
    require_once WP_VAULT_PLUGIN_DIR . 'includes/class-wp-vault-api.php';

    $registered = (bool) get_option('wpv_site_id');
    $api_endpoint = get_option('wpv_api_endpoint', 'https://wpvault.cloud');
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

    // Get connected storage types for reference
    $connected_types = array();
    $s3_compatible_types = array('wasabi', 'b2', 'spaces', 'minio');
    foreach ($storages as $storage) {
        $connected_types[] = $storage['type'];
        // Check if this is an S3-compatible storage
        if (in_array($storage['type'], $s3_compatible_types)) {
            $connected_types[] = 's3-compatible';
        }
    }

    // Supported storage providers (from architecture doc)
    $supported_providers = array(
        'wp_vault_cloud',
        'google_drive',
        'dropbox',
        'onedrive',
        's3',
        'r2',
        's3-compatible',
    );
    ?>

    <div class="wpv-tab-content" id="wpv-tab-storage">
        <div style="padding: 20px;">
            <h2><?php esc_html_e('Storage Configuration', 'wp-vault'); ?></h2>

            <?php if (!$registered): ?>
                <!-- Top Banner: Not Connected -->
                <div
                    style="background: linear-gradient(135deg, #dc2626 0%, #b91c1c 100%); border-radius: 8px; padding: 20px; margin: 20px 0; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
                    <div
                        style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 16px;">
                        <div style="display: flex; align-items: center; gap: 12px; color: #fff;">
                            <span class="dashicons dashicons-dismiss"
                                style="font-size: 24px; width: 24px; height: 24px; color: #fff;"></span>
                            <div>
                                <strong style="font-size: 16px; display: block; margin-bottom: 4px;">
                                    <?php esc_html_e('Not Connected to WP Vault Cloud', 'wp-vault'); ?>
                                </strong>
                                <span style="font-size: 13px; opacity: 0.9;">
                                    <?php esc_html_e('Connect your site to WP Vault Cloud to configure secure cloud storage.', 'wp-vault'); ?>
                                </span>
                            </div>
                        </div>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=wp-vault&tab=settings')); ?>"
                            class="button button-primary"
                            style="background: #fff; color: #dc2626; border-color: #fff; font-weight: 600;">
                            <span class="dashicons dashicons-admin-links"
                                style="font-size: 16px; width: 16px; height: 16px; vertical-align: middle; margin-right: 6px;"></span>
                            <?php esc_html_e('Connect Now', 'wp-vault'); ?>
                        </a>
                    </div>
                </div>
            <?php else: ?>
                <!-- Top Banner: Connected -->
                <div
                    style="background: linear-gradient(135deg, #059669 0%, #047857 100%); border-radius: 8px; padding: 20px; margin: 20px 0; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
                    <div
                        style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 16px;">
                        <div style="display: flex; align-items: center; gap: 12px; color: #fff;">
                            <span class="dashicons dashicons-yes-alt"
                                style="font-size: 24px; width: 24px; height: 24px; color: #fff;"></span>
                            <div>
                                <strong style="font-size: 16px; display: block; margin-bottom: 4px;">
                                    <?php esc_html_e('Connected to WP Vault Cloud', 'wp-vault'); ?>
                                </strong>
                                <span style="font-size: 13px; opacity: 0.9;">
                                    <?php esc_html_e('Your storage credentials are securely managed in the cloud dashboard.', 'wp-vault'); ?>
                                </span>
                            </div>
                        </div>
                        <a href="<?php echo esc_url($api_endpoint . '/dashboard/storage'); ?>" target="_blank" class="button"
                            style="background: rgba(255,255,255,0.2); color: #fff; border-color: rgba(255,255,255,0.3); backdrop-filter: blur(10px);">
                            <span class="dashicons dashicons-external"
                                style="font-size: 16px; width: 16px; height: 16px; vertical-align: middle; margin-right: 6px;"></span>
                            <?php esc_html_e('Manage in Dashboard', 'wp-vault'); ?>
                        </a>
                    </div>
                </div>
            <?php endif; ?>

        </div>

        <!-- Security Explanation Section -->
        <div class="wpv-section">
            <h3 style="margin-bottom: 20px;">
                <?php esc_html_e('Why We Don\'t Store Credentials in WordPress', 'wp-vault'); ?></h3>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 30px;">
                <!-- Security Flaws Card -->
                <div style="background: #fff5f5; border: 1px solid #fecaca; border-radius: 8px; padding: 20px;">
                    <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 16px;">
                        <span class="dashicons dashicons-warning"
                            style="color: #dc2626; font-size: 24px; width: 24px; height: 24px;"></span>
                        <h4 style="margin: 0; color: #991b1b; font-size: 16px;">
                            <?php esc_html_e('Security Flaws of Storing Credentials in WordPress', 'wp-vault'); ?>
                        </h4>
                    </div>
                    <ul style="margin: 0; padding-left: 20px; color: #7f1d1d; line-height: 1.8;">
                        <li style="margin-bottom: 10px;">
                            <strong><?php esc_html_e('CVE-2023-5576 (CVSS 9.3):', 'wp-vault'); ?></strong><br>
                            <span style="font-size: 13px;">
                                <?php esc_html_e('WPVivid had a critical vulnerability where Google Drive API secrets were stored in plaintext in plugin files. Attackers could impersonate the plugin and access any user\'s Google Drive.', 'wp-vault'); ?>
                            </span>
                        </li>
                        <li style="margin-bottom: 10px;">
                            <strong><?php esc_html_e('WordPress Database Vulnerabilities:', 'wp-vault'); ?></strong><br>
                            <span style="font-size: 13px;">
                                <?php esc_html_e('WordPress databases are vulnerable to SQL injection, file access exploits, and credential exposure. Once compromised, all stored credentials are exposed.', 'wp-vault'); ?>
                            </span>
                        </li>
                        <li style="margin-bottom: 10px;">
                            <strong><?php esc_html_e('OAuth Token Exposure:', 'wp-vault'); ?></strong><br>
                            <span style="font-size: 13px;">
                                <?php esc_html_e('OAuth tokens stored in WordPress database are easily accessible. No proper encryption, single breach affects all users.', 'wp-vault'); ?>
                            </span>
                        </li>
                        <li style="margin-bottom: 10px;">
                            <strong><?php esc_html_e('No Audit Logging:', 'wp-vault'); ?></strong><br>
                            <span style="font-size: 13px;">
                                <?php esc_html_e('No tracking of who accessed credentials, no detection of unauthorized access attempts.', 'wp-vault'); ?>
                            </span>
                        </li>
                        <li>
                            <strong><?php esc_html_e('Single Point of Failure:', 'wp-vault'); ?></strong><br>
                            <span style="font-size: 13px;">
                                <?php esc_html_e('All credentials in one place (WordPress DB), no revocation mechanism, shared credentials across sites.', 'wp-vault'); ?>
                            </span>
                        </li>
                    </ul>
                </div>

                <!-- WP Vault Approach Card -->
                <div style="background: #f0fdf4; border: 1px solid #86efac; border-radius: 8px; padding: 20px;">
                    <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 16px;">
                        <span class="dashicons dashicons-shield-alt"
                            style="color: #16a34a; font-size: 24px; width: 24px; height: 24px;"></span>
                        <h4 style="margin: 0; color: #166534; font-size: 16px;">
                            <?php esc_html_e('WP Vault\'s Secure Approach', 'wp-vault'); ?>
                        </h4>
                    </div>
                    <ul style="margin: 0; padding-left: 20px; color: #14532d; line-height: 1.8;">
                        <li style="margin-bottom: 10px;">
                            <strong><?php esc_html_e('Google Secret Manager:', 'wp-vault'); ?></strong><br>
                            <span style="font-size: 13px;">
                                <?php esc_html_e('All credentials are encrypted and stored in Google Secret Manager, never in WordPress. Enterprise-grade security.', 'wp-vault'); ?>
                            </span>
                        </li>
                        <li style="margin-bottom: 10px;">
                            <strong><?php esc_html_e('OAuth Tokens Never Touch WordPress:', 'wp-vault'); ?></strong><br>
                            <span style="font-size: 13px;">
                                <?php esc_html_e('OAuth tokens for Google Drive, Dropbox, and OneDrive are handled entirely by SaaS. WordPress never sees or stores tokens.', 'wp-vault'); ?>
                            </span>
                        </li>
                        <li style="margin-bottom: 10px;">
                            <strong><?php esc_html_e('Centralized Management:', 'wp-vault'); ?></strong><br>
                            <span style="font-size: 13px;">
                                <?php esc_html_e('Manage storage for multiple sites from one secure dashboard. No need to configure each site individually.', 'wp-vault'); ?>
                            </span>
                        </li>
                        <li style="margin-bottom: 10px;">
                            <strong><?php esc_html_e('Full Audit Logging:', 'wp-vault'); ?></strong><br>
                            <span style="font-size: 13px;">
                                <?php esc_html_e('Complete audit trail of storage access, usage, and configuration changes in the Vault Cloud dashboard.', 'wp-vault'); ?>
                            </span>
                        </li>
                        <li>
                            <strong><?php esc_html_e('Per-Site Revocation:', 'wp-vault'); ?></strong><br>
                            <span style="font-size: 13px;">
                                <?php esc_html_e('Revoke access for individual sites without affecting others. Complete control over storage access.', 'wp-vault'); ?>
                            </span>
                        </li>
                    </ul>
                </div>
            </div>
        </div>

        <!-- Supported Storage Providers Grid -->
        <div class="wpv-section" style="margin: 30px 0;">
            <h3 style="margin-bottom: 20px;"><?php esc_html_e('Supported Storage Providers', 'wp-vault'); ?></h3>
            <p class="description" style="margin-bottom: 24px;">
                <?php esc_html_e('All storage providers are configured securely in the WP Vault Cloud dashboard. Credentials never touch your WordPress database.', 'wp-vault'); ?>
            </p>

            <div class="wpv-storage-providers-grid">
                <?php foreach ($supported_providers as $provider_type): ?>
                    <?php
                    $provider_info = wpvault_get_storage_provider_info($provider_type);
                    $is_connected = in_array($provider_type, $connected_types) || ($provider_type === 'wp_vault_cloud' && $registered);
                    $is_s3_compatible = ($provider_type === 's3-compatible');
                    ?>
                    <div class="wpv-storage-provider-card <?php echo $is_connected ? 'wpv-provider-connected' : ''; ?>">
                        <div style="display: flex; align-items: flex-start; gap: 12px; margin-bottom: 12px;">
                            <div style="background: #f6f7f7; border-radius: 8px; padding: 12px; flex-shrink: 0;">
                                <span class="dashicons <?php echo esc_attr($provider_info['icon']); ?>"
                                    style="font-size: 24px; width: 24px; height: 24px; color: #2271b1;"></span>
                            </div>
                            <div style="flex: 1; min-width: 0;">
                                <div
                                    style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 4px;">
                                    <strong style="font-size: 15px; color: #1d2327;">
                                        <?php echo esc_html($provider_info['name']); ?>
                                    </strong>
                                    <?php if ($is_connected): ?>
                                        <span
                                            style="background: #00a32a; color: #fff; padding: 2px 8px; border-radius: 12px; font-size: 11px; font-weight: 600;">
                                            ✓ <?php esc_html_e('Connected', 'wp-vault'); ?>
                                        </span>
                                    <?php else: ?>
                                        <span
                                            style="background: #f0f0f1; color: #646970; padding: 2px 8px; border-radius: 12px; font-size: 11px; font-weight: 600;">
                                            <?php esc_html_e('Available', 'wp-vault'); ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <div style="font-size: 12px; color: #646970; margin-bottom: 8px;">
                                    <?php echo esc_html($provider_info['description']); ?>
                                </div>
                                <div
                                    style="background: #f6f7f7; border-radius: 4px; padding: 6px 8px; font-size: 11px; color: #50575e;">
                                    <strong><?php esc_html_e('Configuration:', 'wp-vault'); ?></strong>
                                    <?php echo esc_html($provider_info['config_method']); ?>
                                </div>
                            </div>
                        </div>
                        <?php if ($is_connected): ?>
                            <div style="margin-top: 12px; padding-top: 12px; border-top: 1px solid #e2e4e7;">
                                <a href="<?php echo esc_url($api_endpoint . '/dashboard/storage'); ?>" target="_blank"
                                    class="button button-small" style="width: 100%; text-align: center;">
                                    <?php esc_html_e('Manage in Dashboard', 'wp-vault'); ?>
                                    <span class="dashicons dashicons-external"
                                        style="font-size: 14px; width: 14px; height: 14px; vertical-align: middle; margin-left: 4px;"></span>
                                </a>
                            </div>
                        <?php else: ?>
                            <div style="margin-top: 12px; padding-top: 12px; border-top: 1px solid #e2e4e7;">
                                <a href="<?php echo esc_url($api_endpoint . '/dashboard/storage'); ?>" target="_blank"
                                    class="button button-primary button-small" style="width: 100%; text-align: center;">
                                    <?php esc_html_e('Configure in Dashboard', 'wp-vault'); ?>
                                    <span class="dashicons dashicons-external"
                                        style="font-size: 14px; width: 14px; height: 14px; vertical-align: middle; margin-left: 4px;"></span>
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Connected Storages Section (Cards Layout) -->
        <?php if (!empty($storages)): ?>
            <div class="wpv-section" style="margin: 30px 0;">
                <h3 style="margin-bottom: 20px;"><?php esc_html_e('Your Connected Storages', 'wp-vault'); ?></h3>

                <div class="wpv-connected-storages-grid">
                    <?php foreach ($storages as $storage): ?>
                        <?php
                        $provider_info = wpvault_get_storage_provider_info($storage['type']);
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
                        $display_name = $type_labels[$storage['type']] ?? ucfirst(str_replace('_', ' ', $storage['type']));
                        ?>
                        <div class="wpv-connected-storage-card <?php echo $storage['is_primary'] ? 'wpv-storage-primary' : ''; ?>">
                            <div style="display: flex; align-items: flex-start; gap: 12px; margin-bottom: 16px;">
                                <div
                                    style="background: <?php echo $storage['is_primary'] ? '#2271b1' : '#f6f7f7'; ?>; border-radius: 8px; padding: 12px; flex-shrink: 0;">
                                    <span class="dashicons <?php echo esc_attr($provider_info['icon']); ?>"
                                        style="font-size: 24px; width: 24px; height: 24px; color: <?php echo $storage['is_primary'] ? '#fff' : '#2271b1'; ?>;"></span>
                                </div>
                                <div style="flex: 1; min-width: 0;">
                                    <div
                                        style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px;">
                                        <strong style="font-size: 15px; color: #1d2327;">
                                            <?php echo esc_html($storage['name']); ?>
                                        </strong>
                                        <?php if ($storage['is_primary']): ?>
                                            <span
                                                style="background: #2271b1; color: #fff; padding: 3px 10px; border-radius: 12px; font-size: 11px; font-weight: 600;">
                                                <?php esc_html_e('Primary', 'wp-vault'); ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    <div style="font-size: 13px; color: #646970; margin-bottom: 4px;">
                                        <?php echo esc_html($display_name); ?>
                                    </div>
                                    <?php if (!empty($storage['account_email'])): ?>
                                        <div style="font-size: 12px; color: #8c8f94;">
                                            <span class="dashicons dashicons-email-alt"
                                                style="font-size: 14px; width: 14px; height: 14px; vertical-align: middle;"></span>
                                            <?php echo esc_html($storage['account_email']); ?>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($storage['type'] === 'wp_vault_cloud' && isset($storage['usage_percent'])): ?>
                                        <div style="margin-top: 8px; font-size: 12px; color: #646970;">
                                            <strong><?php esc_html_e('Usage:', 'wp-vault'); ?></strong>
                                            <?php echo esc_html($storage['usage_percent']); ?>%
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                                <button type="button" class="button button-small test-storage-connection wpv-storage-action-btn"
                                    data-storage-id="<?php echo esc_attr($storage['id']); ?>" style="flex: 1; min-width: 120px;">
                                    <?php esc_html_e('Test Connection', 'wp-vault'); ?>
                                </button>
                                <?php if (!$storage['is_primary']): ?>
                                    <button type="button" class="button button-small set-primary-storage wpv-storage-action-btn"
                                        data-storage-id="<?php echo esc_attr($storage['id']); ?>" style="flex: 1; min-width: 120px;">
                                        <?php esc_html_e('Set as Primary', 'wp-vault'); ?>
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Primary Storage Selection -->
                <div
                    style="margin-top: 30px; padding: 20px; background: #f6f7f7; border-radius: 8px; border: 1px solid #c3c4c7;">
                    <h4 style="margin: 0 0 12px 0;"><?php esc_html_e('Select Primary Storage', 'wp-vault'); ?></h4>
                    <p class="description" style="margin-bottom: 12px;">
                        <?php esc_html_e('Primary storage is used for all new backups. You can change this at any time.', 'wp-vault'); ?>
                    </p>
                    <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
                        <select id="primary-storage-select" class="regular-text" style="min-width: 250px;">
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
                        <button type="button" id="save-primary-storage" class="button button-primary">
                            <?php esc_html_e('Save Primary Storage', 'wp-vault'); ?>
                        </button>
                        <span id="primary-storage-result" style="margin-left: 10px;"></span>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Local Download Option -->
        <div class="wpv-section" style="margin: 30px 0;">
            <h3 style="margin-bottom: 16px;"><?php esc_html_e('Local Downloads', 'wp-vault'); ?></h3>
            <div style="background: #f0f6fc; border: 1px solid #bfdbfe; border-radius: 8px; padding: 16px;">
                <div style="display: flex; align-items: flex-start; gap: 12px;">
                    <span class="dashicons dashicons-download"
                        style="color: #2563eb; font-size: 20px; width: 20px; height: 20px; margin-top: 2px;"></span>
                    <div>
                        <strong style="display: block; margin-bottom: 4px; color: #1e40af;">
                            <?php esc_html_e('Always Available:', 'wp-vault'); ?>
                        </strong>
                        <p style="margin: 0; color: #1e3a8a; font-size: 14px; line-height: 1.6;">
                            <?php esc_html_e('You can always download backups directly to your computer, regardless of cloud storage settings. This works without any Vault Cloud connection.', 'wp-vault'); ?>
                        </p>
                    </div>
                </div>
            </div>
        </div>

    </div>

    <script>
        jQuery(document).ready(function ($) {
            // Test storage connection
            $('.test-storage-connection').on('click', function () {
                var $btn = $(this);
                var storageId = $btn.data('storage-id');
                var $result = $('<span style="margin-left: 10px; font-size: 12px;"></span>');
                $btn.after($result);

                $btn.prop('disabled', true).text('<?php echo esc_js(esc_html__('Testing...', 'wp-vault')); ?>');
                $result.html('');

                // Note: Test connection would need to be implemented in SaaS API
                // For now, just show a message
                setTimeout(function () {
                    $result.html('<span style="color: green;">✓ <?php echo esc_js(esc_html__('Connection test feature coming soon', 'wp-vault')); ?></span>');
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