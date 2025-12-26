<?php
/**
 * Features Tab Content
 * 
 * Feature comparison (Free vs Pro)
 *
 * @package WP_Vault
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}
?>

<div class="wpv-tab-content" id="wpv-tab-features">
    <div class="wpv-section">
        <h2><?php _e('WP Vault Features', 'wp-vault'); ?></h2>

        <div class="wpv-features-comparison">
            <table class="wp-list-table widefat fixed">
                <thead>
                    <tr>
                        <th style="width:40%;"><?php _e('Feature', 'wp-vault'); ?></th>
                        <th style="width:30%; text-align:center;">
                            <span class="wpv-plan-badge wpv-plan-free"><?php _e('Free', 'wp-vault'); ?></span>
                        </th>
                        <th style="width:30%; text-align:center;">
                            <span class="wpv-plan-badge wpv-plan-pro"><?php _e('Pro', 'wp-vault'); ?></span>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><strong><?php _e('Full Backups', 'wp-vault'); ?></strong></td>
                        <td style="text-align:center;"><span class="dashicons dashicons-yes-alt"
                                style="color:#46b450;"></span></td>
                        <td style="text-align:center;"><span class="dashicons dashicons-yes-alt"
                                style="color:#46b450;"></span></td>
                    </tr>
                    <tr>
                        <td><strong><?php _e('Incremental Backups', 'wp-vault'); ?></strong></td>
                        <td style="text-align:center;"><span class="dashicons dashicons-dismiss"
                                style="color:#dc3232;"></span></td>
                        <td style="text-align:center;"><span class="dashicons dashicons-yes-alt"
                                style="color:#46b450;"></span></td>
                    </tr>
                    <tr>
                        <td><strong><?php _e('WP Vault Cloud Storage (3GB)', 'wp-vault'); ?></strong></td>
                        <td style="text-align:center;"><span class="dashicons dashicons-yes-alt"
                                style="color:#46b450;"></span></td>
                        <td style="text-align:center;"><span class="dashicons dashicons-yes-alt"
                                style="color:#46b450;"></span></td>
                    </tr>
                    <tr>
                        <td><strong><?php _e('S3-Compatible Storage', 'wp-vault'); ?></strong></td>
                        <td style="text-align:center;"><span class="dashicons dashicons-dismiss"
                                style="color:#dc3232;"></span></td>
                        <td style="text-align:center;"><span class="dashicons dashicons-yes-alt"
                                style="color:#46b450;"></span></td>
                    </tr>
                    <tr>
                        <td><strong><?php _e('OAuth Storage (Google Drive, Dropbox, OneDrive)', 'wp-vault'); ?></strong>
                        </td>
                        <td style="text-align:center;"><span class="dashicons dashicons-dismiss"
                                style="color:#dc3232;"></span></td>
                        <td style="text-align:center;"><span class="dashicons dashicons-yes-alt"
                                style="color:#46b450;"></span></td>
                    </tr>
                    <tr>
                        <td><strong><?php _e('Scheduled Backups', 'wp-vault'); ?></strong></td>
                        <td style="text-align:center;"><span class="dashicons dashicons-dismiss"
                                style="color:#dc3232;"></span></td>
                        <td style="text-align:center;"><span class="dashicons dashicons-yes-alt"
                                style="color:#46b450;"></span></td>
                    </tr>
                    <tr>
                        <td><strong><?php _e('Restore Functionality', 'wp-vault'); ?></strong></td>
                        <td style="text-align:center;"><span class="dashicons dashicons-yes-alt"
                                style="color:#46b450;"></span></td>
                        <td style="text-align:center;"><span class="dashicons dashicons-yes-alt"
                                style="color:#46b450;"></span></td>
                    </tr>
                    <tr>
                        <td><strong><?php _e('Selective Restore', 'wp-vault'); ?></strong></td>
                        <td style="text-align:center;"><span class="dashicons dashicons-yes-alt"
                                style="color:#46b450;"></span></td>
                        <td style="text-align:center;"><span class="dashicons dashicons-yes-alt"
                                style="color:#46b450;"></span></td>
                    </tr>
                    <tr>
                        <td><strong><?php _e('Multiple Sites Management', 'wp-vault'); ?></strong></td>
                        <td style="text-align:center;"><span class="dashicons dashicons-dismiss"
                                style="color:#dc3232;"></span></td>
                        <td style="text-align:center;"><span class="dashicons dashicons-yes-alt"
                                style="color:#46b450;"></span></td>
                    </tr>
                    <tr>
                        <td><strong><?php _e('Priority Support', 'wp-vault'); ?></strong></td>
                        <td style="text-align:center;"><span class="dashicons dashicons-dismiss"
                                style="color:#dc3232;"></span></td>
                        <td style="text-align:center;"><span class="dashicons dashicons-yes-alt"
                                style="color:#46b450;"></span></td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="wpv-upgrade-section">
            <h3><?php _e('Upgrade to Pro', 'wp-vault'); ?></h3>
            <p><?php _e('Unlock all features including incremental backups, multiple storage options, scheduled backups, and priority support.', 'wp-vault'); ?>
            </p>
            <a href="https://wpvault.cloud/pricing" target="_blank" class="button button-primary">
                <?php _e('View Pricing & Upgrade', 'wp-vault'); ?>
                <span class="dashicons dashicons-external"
                    style="font-size: 14px; vertical-align: middle; margin-left: 5px;"></span>
            </a>
        </div>
    </div>
</div>