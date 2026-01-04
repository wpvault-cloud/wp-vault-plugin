<?php
/**
 * Community & Support Tab Content
 * 
 * Discord community widget and support resources
 *
 * @package WP_Vault
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}
?>

<div class="wpv-tab-content" id="wpv-tab-community-support">
    <div class="wpv-section">
        <h2><?php esc_html_e('Community & Support', 'wp-vault'); ?></h2>
        <p class="description">
            <?php esc_html_e('Join our Discord community for support, updates, and discussions with other WPVault users.', 'wp-vault'); ?>
        </p>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-top: 20px;">
            <!-- Discord Widget -->
            <div class="wpv-card" style="background: #fff; border: 1px solid #ddd; border-radius: 4px; padding: 20px;">
                <h3><?php esc_html_e('Discord Community', 'wp-vault'); ?></h3>
                <p class="description">
                    <?php esc_html_e('Join our Discord server to get help, share feedback, and connect with the community.', 'wp-vault'); ?>
                </p>

                <!-- Discord Widget iframe -->
                <div style="margin-top: 20px; text-align: center;">
                    <iframe src="https://discord.com/widget?id=1457083218040852601&theme=dark" width="350" height="500"
                        allowtransparency="true" frameborder="0"
                        sandbox="allow-popups allow-popups-to-escape-sandbox allow-same-origin allow-scripts"
                        style="max-width: 100%; border-radius: 4px;">
                    </iframe>
                </div>

                <div style="margin-top: 15px; text-align: center;">
                    <a href="https://discord.gg/3PqKgZQWU3" target="_blank" rel="noopener noreferrer"
                        class="button button-primary">
                        <?php esc_html_e('Join Discord Server', 'wp-vault'); ?>
                    </a>
                </div>
            </div>

            <!-- Help & Support Section -->
            <div class="wpv-card" style="background: #fff; border: 1px solid #ddd; border-radius: 4px; padding: 20px;">
                <h3><?php esc_html_e('Help & Support', 'wp-vault'); ?></h3>
                <p class="description">
                    <?php esc_html_e('Get help and support from various resources.', 'wp-vault'); ?>
                </p>

                <div style="margin-top: 20px;">
                    <div style="margin-bottom: 20px; padding: 15px; background: #f5f5f5; border-radius: 4px;">
                        <h4 style="margin-top: 0;">
                            <span class="dashicons dashicons-groups"
                                style="vertical-align: middle; margin-right: 5px;"></span>
                            <?php esc_html_e('Discord Community', 'wp-vault'); ?>
                        </h4>
                        <p>
                            <?php esc_html_e('Join our Discord server for real-time support, discussions, and community updates.', 'wp-vault'); ?>
                        </p>
                        <a href="https://discord.gg/3PqKgZQWU3" target="_blank" rel="noopener noreferrer"
                            class="button button-secondary">
                            <?php esc_html_e('Join Discord →', 'wp-vault'); ?>
                        </a>
                    </div>

                    <div style="margin-bottom: 20px; padding: 15px; background: #f5f5f5; border-radius: 4px;">
                        <h4 style="margin-top: 0;">
                            <span class="dashicons dashicons-book-alt"
                                style="vertical-align: middle; margin-right: 5px;"></span>
                            <?php esc_html_e('Documentation', 'wp-vault'); ?>
                        </h4>
                        <p>
                            <?php esc_html_e('Browse our comprehensive documentation for guides, tutorials, and FAQs.', 'wp-vault'); ?>
                        </p>
                        <a href="https://wpvault.cloud/docs" target="_blank" rel="noopener noreferrer"
                            class="button button-secondary">
                            <?php esc_html_e('View Documentation →', 'wp-vault'); ?>
                        </a>
                    </div>

                    <div style="margin-bottom: 20px; padding: 15px; background: #f5f5f5; border-radius: 4px;">
                        <h4 style="margin-top: 0;">
                            <span class="dashicons dashicons-github"
                                style="vertical-align: middle; margin-right: 5px;"></span>
                            <?php esc_html_e('GitHub Repository', 'wp-vault'); ?>
                        </h4>
                        <p>
                            <?php esc_html_e('Report bugs, request features, or contribute to the project on GitHub.', 'wp-vault'); ?>
                        </p>
                        <a href="https://github.com/wpvault-cloud/wp-vault-plugin" target="_blank"
                            rel="noopener noreferrer" class="button button-secondary">
                            <?php esc_html_e('View on GitHub →', 'wp-vault'); ?>
                        </a>
                    </div>

                    <div style="padding: 15px; background: #f5f5f5; border-radius: 4px;">
                        <h4 style="margin-top: 0;">
                            <span class="dashicons dashicons-email-alt"
                                style="vertical-align: middle; margin-right: 5px;"></span>
                            <?php esc_html_e('Email Support', 'wp-vault'); ?>
                        </h4>
                        <p>
                            <?php esc_html_e('Contact us directly via email for premium support and enterprise inquiries.', 'wp-vault'); ?>
                        </p>
                        <a href="mailto:support@wpvault.cloud" class="button button-secondary">
                            <?php esc_html_e('Email Support →', 'wp-vault'); ?>
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Community Stats (Optional - can fetch from Discord API) -->
        <div class="wpv-card"
            style="background: #fff; border: 1px solid #ddd; border-radius: 4px; padding: 20px; margin-top: 20px;">
            <h3><?php esc_html_e('Community Statistics', 'wp-vault'); ?></h3>
            <div id="wpv-discord-stats"
                style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-top: 15px;">
                <div style="text-align: center; padding: 15px; background: #f5f5f5; border-radius: 4px;">
                    <div style="font-size: 32px; font-weight: bold; color: #2271b1;" id="wpv-discord-members">-</div>
                    <div style="color: #666; margin-top: 5px;"><?php esc_html_e('Community Members', 'wp-vault'); ?>
                    </div>
                </div>
                <div style="text-align: center; padding: 15px; background: #f5f5f5; border-radius: 4px;">
                    <div style="font-size: 32px; font-weight: bold; color: #2271b1;" id="wpv-discord-online">-</div>
                    <div style="color: #666; margin-top: 5px;"><?php esc_html_e('Online Now', 'wp-vault'); ?></div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    jQuery(document).ready(function ($) {
        // Fetch Discord widget data
        $.getJSON('https://discord.com/api/guilds/1457083218040852601/widget.json')
            .done(function (data) {
                if (data && data.members) {
                    $('#wpv-discord-members').text(data.members.length);
                    var onlineCount = data.members.filter(function (member) {
                        return member.status === 'online' || member.status === 'idle' || member.status === 'dnd';
                    }).length;
                    $('#wpv-discord-online').text(onlineCount);
                }
            })
            .fail(function () {
                console.log('Failed to load Discord stats');
            });
    });
</script>