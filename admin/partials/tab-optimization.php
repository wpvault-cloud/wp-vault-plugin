<?php
/**
 * Optimization Tab Content
 * 
 * Media, Content, SEO, and Database optimization
 *
 * @package WP_Vault
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once WP_VAULT_PLUGIN_DIR . 'includes/class-wp-vault-media-optimizer.php';

/**
 * Display optimization tab content
 */
function wpvault_display_optimization_tab()
{
    // Get current sub-tab from URL
    $current_subtab = isset($_GET['subtab']) ? sanitize_text_field($_GET['subtab']) : 'media';
    $valid_subtabs = array('media', 'content', 'seo', 'database', 'compression_settings');
    if (!in_array($current_subtab, $valid_subtabs)) {
        $current_subtab = 'media';
    }

    // Get optimization settings
    $settings = get_option('wpv_optimization_settings', array(
        'compression_method' => 'php_native',
        'quality' => 80,
        'output_format' => 'auto',
        'max_width' => 2048,
        'max_height' => 2048,
        'keep_original' => true,
    ));

    // Get system capabilities
    $capabilities = \WP_Vault\WP_Vault_Media_Optimizer::check_php_capabilities();

    // Get optimization statistics
    $stats = \WP_Vault\WP_Vault_Media_Optimizer::get_optimization_stats();

    // Check WPVault Cloud connection
    $is_connected = (bool) get_option('wpv_site_id');
    ?>

    <div class="wpv-tab-content" id="wpv-tab-optimization">
        <div class="wpv-section">
            <h2><?php esc_html_e('Optimization', 'wp-vault'); ?></h2>
            <p class="description">
                <?php esc_html_e('Optimize your WordPress site for better performance, SEO, and user experience.', 'wp-vault'); ?>
            </p>

            <!-- Sub-tab Navigation -->
            <div class="wpv-subtabs" style="margin: 20px 0; border-bottom: 1px solid #ddd;">
                <a href="<?php echo esc_url(admin_url('admin.php?page=wp-vault&tab=optimization&subtab=media')); ?>"
                    class="wpv-subtab <?php echo $current_subtab === 'media' ? 'wpv-subtab-active' : ''; ?>"
                    style="display: inline-block; padding: 10px 20px; text-decoration: none; color: #2271b1; border-bottom: 2px solid transparent; margin-right: 10px;">
                    <?php esc_html_e('Media', 'wp-vault'); ?>
                </a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=wp-vault&tab=optimization&subtab=content')); ?>"
                    class="wpv-subtab <?php echo $current_subtab === 'content' ? 'wpv-subtab-active' : ''; ?>"
                    style="display: inline-block; padding: 10px 20px; text-decoration: none; color: #2271b1; border-bottom: 2px solid transparent; margin-right: 10px;">
                    <?php esc_html_e('Content', 'wp-vault'); ?>
                </a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=wp-vault&tab=optimization&subtab=seo')); ?>"
                    class="wpv-subtab <?php echo $current_subtab === 'seo' ? 'wpv-subtab-active' : ''; ?>"
                    style="display: inline-block; padding: 10px 20px; text-decoration: none; color: #2271b1; border-bottom: 2px solid transparent; margin-right: 10px;">
                    <?php esc_html_e('SEO', 'wp-vault'); ?>
                </a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=wp-vault&tab=optimization&subtab=database')); ?>"
                    class="wpv-subtab <?php echo $current_subtab === 'database' ? 'wpv-subtab-active' : ''; ?>"
                    style="display: inline-block; padding: 10px 20px; text-decoration: none; color: #2271b1; border-bottom: 2px solid transparent; margin-right: 10px;">
                    <?php esc_html_e('Database', 'wp-vault'); ?>
                </a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=wp-vault&tab=optimization&subtab=compression_settings')); ?>"
                    class="wpv-subtab <?php echo $current_subtab === 'compression_settings' ? 'wpv-subtab-active' : ''; ?>"
                    style="display: inline-block; padding: 10px 20px; text-decoration: none; color: #2271b1; border-bottom: 2px solid transparent; margin-right: 10px;">
                    <?php esc_html_e('Compression Settings', 'wp-vault'); ?>
                </a>
            </div>

            <style>
                .wpv-subtab-active {
                    border-bottom-color: #2271b1 !important;
                    font-weight: 600;
                }

                .wpv-settings-panel {
                    background: #fff;
                    border: 1px solid #ddd;
                    border-radius: 4px;
                    padding: 20px;
                    margin-bottom: 20px;
                }

                .wpv-settings-panel h3 {
                    margin-top: 0;
                }

                .wpv-method-option {
                    border: 1px solid #ddd;
                    border-radius: 4px;
                    padding: 15px;
                    margin-bottom: 15px;
                    background: #f8f9fa;
                }

                .wpv-method-option input[type="radio"] {
                    margin-right: 10px;
                }

                .wpv-method-option.selected {
                    border-color: #2271b1;
                    background: #f0f6fc;
                }

                .wpv-system-check {
                    background: #fff;
                    border: 1px solid #ddd;
                    border-radius: 4px;
                    padding: 10px;
                    margin-top: 10px;
                }

                .wpv-system-check-item {
                    display: flex;
                    align-items: center;
                    padding: 5px 0;
                }

                .wpv-system-check-item .dashicons {
                    margin-right: 8px;
                }

                .wpv-system-check-item .dashicons-yes {
                    color: #46b450;
                }

                .wpv-system-check-item .dashicons-no {
                    color: #dc3232;
                }

                .wpv-stats-card {
                    background: #fff;
                    border: 1px solid #ddd;
                    border-radius: 4px;
                    padding: 20px;
                    margin-bottom: 20px;
                }

                .wpv-stats-card h3 {
                    margin-top: 0;
                }

                .wpv-stats-grid {
                    display: grid;
                    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
                    gap: 15px;
                    margin-top: 15px;
                }

                .wpv-stat-item {
                    text-align: center;
                }

                .wpv-stat-value {
                    font-size: 24px;
                    font-weight: bold;
                    color: #2271b1;
                }

                .wpv-stat-label {
                    font-size: 12px;
                    color: #666;
                    margin-top: 5px;
                }

                .wpv-media-gallery {
                    display: grid;
                    grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
                    gap: 20px;
                    margin-top: 20px;
                }

                .wpv-media-item {
                    border: 1px solid #ddd;
                    border-radius: 4px;
                    padding: 10px;
                    background: #fff;
                    transition: box-shadow 0.2s;
                    position: relative;
                }

                .wpv-media-item:hover {
                    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
                }

                .wpv-media-item[data-optimized="true"] {
                    border-color: #46b450;
                }

                .wpv-media-item[data-optimized="false"] {
                    border-color: #ffb900;
                }

                .wpv-optimization-badge {
                    position: absolute;
                    top: 10px;
                    right: 10px;
                    padding: 4px 8px;
                    border-radius: 3px;
                    font-size: 10px;
                    font-weight: bold;
                    text-transform: uppercase;
                }

                .wpv-optimization-badge.optimized {
                    background: #46b450;
                    color: #fff;
                }

                .wpv-optimization-badge.unoptimized {
                    background: #ffb900;
                    color: #000;
                }

                .wpv-media-thumbnail {
                    width: 100%;
                    height: 150px;
                    object-fit: cover;
                    border-radius: 4px;
                    margin-bottom: 10px;
                }

                .wpv-media-info {
                    font-size: 12px;
                    color: #666;
                }

                .wpv-media-info strong {
                    display: block;
                    margin-bottom: 5px;
                    color: #333;
                }

                .wpv-quick-actions {
                    background: #f8f9fa;
                    border: 1px solid #ddd;
                    border-radius: 4px;
                    padding: 20px;
                    margin-bottom: 20px;
                }

                .wpv-quick-actions h3 {
                    margin-top: 0;
                    margin-bottom: 15px;
                }

                .wpv-quick-actions .button {
                    margin-right: 10px;
                }

                .wpv-output-options {
                    margin-top: 15px;
                    padding-top: 15px;
                    border-top: 1px solid #ddd;
                }

                .wpv-output-options label {
                    display: block;
                    margin-bottom: 10px;
                }

                .wpv-output-options input[type="number"],
                .wpv-output-options select {
                    width: 100%;
                    max-width: 200px;
                }
            </style>

            <!-- Sub-tab Content -->
            <?php
            switch ($current_subtab) {
                case 'media':
                    ?>
                    <!-- Media Tab -->
                    <div class="wpv-subtab-content">
                        <!-- Statistics Card -->
                        <div class="wpv-stats-card">
                            <h3><?php esc_html_e('📊 Optimization Statistics', 'wp-vault'); ?></h3>
                            <div class="wpv-stats-grid">
                                <div class="wpv-stat-item">
                                    <div class="wpv-stat-value" id="wpv-stats-total"><?php echo esc_html($stats['total_images']); ?>
                                    </div>
                                    <div class="wpv-stat-label"><?php esc_html_e('Total Images', 'wp-vault'); ?></div>
                                </div>
                                <div class="wpv-stat-item">
                                    <div class="wpv-stat-value" id="wpv-stats-optimized" style="color: #46b450;">
                                        <?php echo esc_html($stats['optimized_count']); ?></div>
                                    <div class="wpv-stat-label"><?php esc_html_e('Optimized', 'wp-vault'); ?></div>
                                </div>
                                <div class="wpv-stat-item">
                                    <div class="wpv-stat-value" id="wpv-stats-unoptimized" style="color: #ffb900;">
                                        <?php echo esc_html($stats['unoptimized_count']); ?></div>
                                    <div class="wpv-stat-label"><?php esc_html_e('Unoptimized', 'wp-vault'); ?></div>
                                </div>
                                <div class="wpv-stat-item">
                                    <div class="wpv-stat-value" id="wpv-stats-space-saved">
                                        <?php echo esc_html(size_format($stats['total_space_saved'], 2)); ?></div>
                                    <div class="wpv-stat-label"><?php esc_html_e('Space Saved', 'wp-vault'); ?></div>
                                </div>
                                <div class="wpv-stat-item">
                                    <div class="wpv-stat-value" id="wpv-stats-avg-compression">
                                        <?php echo esc_html($stats['avg_compression_ratio']); ?>%</div>
                                    <div class="wpv-stat-label"><?php esc_html_e('Avg Compression', 'wp-vault'); ?></div>
                                </div>
                            </div>
                        </div>

                        <!-- Quick Actions Card -->
                        <div class="wpv-quick-actions">
                            <h3><?php esc_html_e('Quick Actions', 'wp-vault'); ?></h3>
                            <p class="description">
                                <?php esc_html_e('Perform bulk optimization actions on your media library.', 'wp-vault'); ?>
                            </p>
                            <button type="button" class="button button-primary" id="wpv-convert-webp">
                                <?php esc_html_e('Convert to WebP', 'wp-vault'); ?>
                            </button>
                            <button type="button" class="button button-primary" id="wpv-compress-all">
                                <?php esc_html_e('Compress All', 'wp-vault'); ?>
                            </button>
                            <div id="wpv-bulk-progress" style="margin-top: 10px; display: none;"></div>
                        </div>

                        <!-- Media Gallery -->
                        <h3><?php esc_html_e('Latest Media Items', 'wp-vault'); ?></h3>
                        <div class="wpv-media-gallery">
                            <?php
                            // Fetch latest 20 media items
                            $media_items = get_posts(array(
                                'post_type' => 'attachment',
                                'post_mime_type' => 'image',
                                'posts_per_page' => 20,
                                'orderby' => 'date',
                                'order' => 'DESC'
                            ));

                            if (empty($media_items)) {
                                ?>
                                <div style="grid-column: 1 / -1; text-align: center; padding: 40px; color: #666;">
                                    <p><?php esc_html_e('No media items found.', 'wp-vault'); ?></p>
                                </div>
                                <?php
                            } else {
                                foreach ($media_items as $media_item) {
                                    $attachment_id = $media_item->ID;
                                    $thumbnail = wp_get_attachment_image_src($attachment_id, 'thumbnail');
                                    $file_path = get_attached_file($attachment_id);
                                    $file_size = file_exists($file_path) ? filesize($file_path) : 0;
                                    $metadata = wp_get_attachment_metadata($attachment_id);
                                    $width = isset($metadata['width']) ? $metadata['width'] : 0;
                                    $height = isset($metadata['height']) ? $metadata['height'] : 0;
                                    $upload_date = get_the_date('Y-m-d H:i', $attachment_id);

                                    // Check optimization status
                                    $optimization_status = \WP_Vault\WP_Vault_Media_Optimizer::get_optimization_status($attachment_id);
                                    $is_optimized = !empty($optimization_status) && $optimization_status['status'] === 'completed';
                                    ?>
                                    <div class="wpv-media-item" data-optimized="<?php echo $is_optimized ? 'true' : 'false'; ?>"
                                        data-attachment-id="<?php echo esc_attr($attachment_id); ?>">
                                        <span class="wpv-optimization-badge <?php echo $is_optimized ? 'optimized' : 'unoptimized'; ?>">
                                            <?php echo $is_optimized ? esc_html__('✓ Optimized', 'wp-vault') : esc_html__('Unoptimized', 'wp-vault'); ?>
                                        </span>
                                        <?php if ($thumbnail): ?>
                                            <img src="<?php echo esc_url($thumbnail[0]); ?>"
                                                alt="<?php echo esc_attr($media_item->post_title); ?>" class="wpv-media-thumbnail">
                                        <?php else: ?>
                                            <div class="wpv-media-thumbnail"
                                                style="background: #f0f0f0; display: flex; align-items: center; justify-content: center; color: #999;">
                                                <?php esc_html_e('No Image', 'wp-vault'); ?>
                                            </div>
                                        <?php endif; ?>
                                        <div class="wpv-media-info">
                                            <strong><?php echo esc_html($media_item->post_title); ?></strong>
                                            <?php if ($is_optimized && $optimization_status): ?>
                                                <?php
                                                $original_size = isset($optimization_status['original_size']) ? (int) $optimization_status['original_size'] : 0;
                                                $compressed_size = isset($optimization_status['compressed_size']) ? (int) $optimization_status['compressed_size'] : $file_size;
                                                ?>
                                                <div style="margin-top: 5px;">
                                                    <span
                                                        style="color: #666;"><?php echo esc_html(sprintf(__('Original: %s', 'wp-vault'), size_format($original_size))); ?></span>
                                                    <span style="margin: 0 5px; color: #999;">→</span>
                                                    <span
                                                        style="color: #2271b1; font-weight: bold;"><?php echo esc_html(sprintf(__('Compressed: %s', 'wp-vault'), size_format($compressed_size))); ?></span>
                                                </div>
                                                <div style="color: #46b450; font-weight: bold; margin-top: 3px;">
                                                    <?php echo esc_html(sprintf(
                                                        __('Saved: %s (%s%%)', 'wp-vault'),
                                                        size_format($optimization_status['space_saved']),
                                                        number_format($optimization_status['compression_ratio'], 1)
                                                    )); ?>
                                                </div>
                                            <?php elseif ($file_size > 0): ?>
                                                <div style="margin-top: 5px; color: #666;">
                                                    <?php echo esc_html(sprintf(__('Size: %s', 'wp-vault'), size_format($file_size))); ?>
                                                </div>
                                            <?php endif; ?>
                                            <?php if ($width > 0 && $height > 0): ?>
                                                <div><?php echo esc_html(sprintf(__('Dimensions: %dx%d', 'wp-vault'), $width, $height)); ?>
                                                </div>
                                            <?php endif; ?>
                                            <div><?php echo esc_html(sprintf(__('Uploaded: %s', 'wp-vault'), $upload_date)); ?></div>
                                            <div style="margin-top: 10px; display: flex; gap: 5px;">
                                                <button type="button" class="button button-small wpv-optimize-single-btn"
                                                    data-attachment-id="<?php echo esc_attr($attachment_id); ?>"
                                                    data-original-size="<?php echo esc_attr($is_optimized && isset($optimization_status['original_size']) ? $optimization_status['original_size'] : $file_size); ?>">
                                                    <?php esc_html_e('Optimize', 'wp-vault'); ?>
                                                </button>
                                                <?php if ($is_optimized && $optimization_status): ?>
                                                    <button type="button" class="button button-small wpv-show-difference-btn"
                                                        data-attachment-id="<?php echo esc_attr($attachment_id); ?>">
                                                        <?php esc_html_e('Show Difference', 'wp-vault'); ?>
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                    <?php
                                }
                            }
                            ?>
                        </div>
                    </div>
                    <?php
                    break;

                case 'content':
                    ?>
                    <!-- Content Tab -->
                    <div class="wpv-subtab-content">
                        <div style="text-align: center; padding: 60px 20px; color: #666;">
                            <h3><?php esc_html_e('Content Optimization', 'wp-vault'); ?></h3>
                            <p><?php esc_html_e('Coming Soon', 'wp-vault'); ?></p>
                            <p class="description">
                                <?php esc_html_e('Content optimization features will be available in a future update.', 'wp-vault'); ?>
                            </p>
                        </div>
                    </div>
                    <?php
                    break;

                case 'seo':
                    ?>
                    <!-- SEO Tab -->
                    <div class="wpv-subtab-content">
                        <div style="text-align: center; padding: 60px 20px; color: #666;">
                            <h3><?php esc_html_e('SEO Optimization', 'wp-vault'); ?></h3>
                            <p><?php esc_html_e('Coming Soon', 'wp-vault'); ?></p>
                            <p class="description">
                                <?php esc_html_e('SEO optimization features will be available in a future update.', 'wp-vault'); ?>
                            </p>
                        </div>
                    </div>
                    <?php
                    break;

                case 'database':
                    ?>
                    <!-- Database Tab -->
                    <div class="wpv-subtab-content">
                        <div style="text-align: center; padding: 60px 20px; color: #666;">
                            <h3><?php esc_html_e('Database Optimization', 'wp-vault'); ?></h3>
                            <p><?php esc_html_e('Coming Soon', 'wp-vault'); ?></p>
                            <p class="description">
                                <?php esc_html_e('Database optimization features will be available in a future update.', 'wp-vault'); ?>
                            </p>
                        </div>
                    </div>
                    <?php
                    break;

                case 'compression_settings':
                    ?>
                    <!-- Compression Settings Tab -->
                    <div class="wpv-subtab-content">
                        <div class="wpv-settings-panel">
                            <h3><?php esc_html_e('🔧 Compression Settings', 'wp-vault'); ?></h3>
                            <p class="description">
                                <?php esc_html_e('Configure default compression settings for image optimization.', 'wp-vault'); ?>
                            </p>

                            <form id="wpv-optimization-settings-form">
                                <div>
                                    <label style="display: block; margin-bottom: 10px; font-weight: 600;">
                                        <?php esc_html_e('Compression Method:', 'wp-vault'); ?>
                                    </label>

                                    <!-- Native PHP Option -->
                                    <div
                                        class="wpv-method-option <?php echo $settings['compression_method'] === 'php_native' ? 'selected' : ''; ?>">
                                        <label>
                                            <input type="radio" name="compression_method" value="php_native" <?php checked($settings['compression_method'], 'php_native'); ?>>
                                            <strong><?php esc_html_e('Native PHP (Default) - FREE', 'wp-vault'); ?></strong>
                                        </label>
                                        <p class="description" style="margin: 5px 0 0 25px;">
                                            <?php esc_html_e('Uses server\'s GD/Imagick libraries', 'wp-vault'); ?>
                                        </p>
                                        <div class="wpv-system-check">
                                            <div class="wpv-system-check-item">
                                                <span
                                                    class="dashicons <?php echo $capabilities['gd'] ? 'dashicons-yes' : 'dashicons-no'; ?>"></span>
                                                <?php esc_html_e('GD Library:', 'wp-vault'); ?>
                                                <?php echo $capabilities['gd'] ? esc_html__('Available', 'wp-vault') : esc_html__('Not Available', 'wp-vault'); ?>
                                            </div>
                                            <div class="wpv-system-check-item">
                                                <span
                                                    class="dashicons <?php echo $capabilities['imagick'] ? 'dashicons-yes' : 'dashicons-no'; ?>"></span>
                                                <?php esc_html_e('Imagick:', 'wp-vault'); ?>
                                                <?php echo $capabilities['imagick'] ? esc_html__('Available', 'wp-vault') : esc_html__('Not Available', 'wp-vault'); ?>
                                            </div>
                                            <div class="wpv-system-check-item">
                                                <span
                                                    class="dashicons <?php echo $capabilities['can_convert_webp'] ? 'dashicons-yes' : 'dashicons-no'; ?>"></span>
                                                <?php esc_html_e('WebP Support:', 'wp-vault'); ?>
                                                <?php echo $capabilities['can_convert_webp'] ? esc_html__('Available', 'wp-vault') : esc_html__('Not Available', 'wp-vault'); ?>
                                            </div>
                                            <div class="wpv-system-check-item">
                                                <span
                                                    class="dashicons <?php echo $capabilities['zlib'] ? 'dashicons-yes' : 'dashicons-no'; ?>"></span>
                                                <?php esc_html_e('Zlib Extension:', 'wp-vault'); ?>
                                                <?php echo $capabilities['zlib'] ? esc_html__('Available', 'wp-vault') : esc_html__('Not Available', 'wp-vault'); ?>
                                            </div>
                                            <?php if (!$capabilities['can_optimize']): ?>
                                                <div class="wpv-system-check-item" style="color: #dc3232; margin-top: 10px;">
                                                    <strong><?php esc_html_e('⚠️ Warning: Native PHP optimization requires GD or Imagick extension.', 'wp-vault'); ?></strong>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <!-- JavaScript Option -->
                                    <div
                                        class="wpv-method-option <?php echo $settings['compression_method'] === 'javascript' ? 'selected' : ''; ?>">
                                        <label>
                                            <input type="radio" name="compression_method" value="javascript" <?php checked($settings['compression_method'], 'javascript'); ?>>
                                            <strong><?php esc_html_e('JavaScript (Client-side) - FREE', 'wp-vault'); ?></strong>
                                        </label>
                                        <p class="description" style="margin: 5px 0 0 25px;">
                                            <?php esc_html_e('Compresses in your browser - zero server load. Powered by browser-image-compression library.', 'wp-vault'); ?>
                                        </p>
                                    </div>

                                    <!-- Server-side Option -->
                                    <div
                                        class="wpv-method-option <?php echo $settings['compression_method'] === 'server_side' ? 'selected' : ''; ?>">
                                        <label>
                                            <input type="radio" name="compression_method" value="server_side" <?php checked($settings['compression_method'], 'server_side'); ?>             <?php echo !$is_connected ? 'disabled' : ''; ?>>
                                            <strong><?php esc_html_e('Server-side (Best Results)', 'wp-vault'); ?></strong>
                                        </label>
                                        <p class="description" style="margin: 5px 0 0 25px;">
                                            <?php if ($is_connected): ?>
                                                <?php esc_html_e('Uses WPVault Cloud for best compression results.', 'wp-vault'); ?>
                                            <?php else: ?>
                                                <span style="color: #dc3232;">
                                                    <strong>⚠️
                                                        <?php esc_html_e('Requires WPVault Cloud connection', 'wp-vault'); ?></strong>
                                                </span>
                                                <br>
                                                <a href="<?php echo esc_url(admin_url('admin.php?page=wp-vault&tab=storage')); ?>"
                                                    class="button button-small" style="margin-top: 5px;">
                                                    <?php esc_html_e('Connect to WPVault Cloud →', 'wp-vault'); ?>
                                                </a>
                                            <?php endif; ?>
                                        </p>
                                    </div>
                                </div>

                                <!-- Output Options -->
                                <div class="wpv-output-options">
                                    <h4><?php esc_html_e('Output Options:', 'wp-vault'); ?></h4>
                                    <label>
                                        <?php esc_html_e('Output Format:', 'wp-vault'); ?>
                                        <select id="wpv-output-format" name="output_format" style="margin-left: 10px;">
                                            <option value="auto" <?php selected($settings['output_format'], 'auto'); ?>>
                                                <?php esc_html_e('Auto', 'wp-vault'); ?></option>
                                            <option value="webp" <?php selected($settings['output_format'], 'webp'); ?>>
                                                <?php esc_html_e('WebP', 'wp-vault'); ?></option>
                                            <option value="jpeg" <?php selected($settings['output_format'], 'jpeg'); ?>>
                                                <?php esc_html_e('JPEG', 'wp-vault'); ?></option>
                                            <option value="png" <?php selected($settings['output_format'], 'png'); ?>>
                                                <?php esc_html_e('PNG', 'wp-vault'); ?></option>
                                            <option value="original" <?php selected($settings['output_format'], 'original'); ?>>
                                                <?php esc_html_e('Original', 'wp-vault'); ?></option>
                                        </select>
                                    </label>
                                    <br>
                                    <label>
                                        <?php esc_html_e('Quality:', 'wp-vault'); ?>
                                        <input type="number" id="wpv-quality" name="quality"
                                            value="<?php echo esc_attr($settings['quality']); ?>" min="1" max="100"
                                            style="margin-left: 10px; width: 80px;">
                                        <span class="description">(1-100)</span>
                                    </label>
                                    <br>
                                    <label>
                                        <?php esc_html_e('Max Width:', 'wp-vault'); ?>
                                        <input type="number" id="wpv-max-width" name="max_width"
                                            value="<?php echo esc_attr($settings['max_width']); ?>" min="100" max="4096"
                                            style="margin-left: 10px; width: 100px;">
                                        <span class="description">px</span>
                                    </label>
                                    <br>
                                    <label>
                                        <?php esc_html_e('Max Height:', 'wp-vault'); ?>
                                        <input type="number" id="wpv-max-height" name="max_height"
                                            value="<?php echo esc_attr($settings['max_height']); ?>" min="100" max="4096"
                                            style="margin-left: 10px; width: 100px;">
                                        <span class="description">px</span>
                                    </label>
                                    <br>
                                    <label>
                                        <input type="checkbox" id="wpv-keep-original" name="keep_original" value="1" <?php checked($settings['keep_original']); ?>>
                                        <?php esc_html_e('Keep original file as backup', 'wp-vault'); ?>
                                    </label>
                                </div>

                                <p>
                                    <button type="button" class="button button-primary" id="wpv-save-settings">
                                        <?php esc_html_e('Save Settings', 'wp-vault'); ?>
                                    </button>
                                </p>
                            </form>
                        </div>
                    </div>
                    <?php
                    break;
            }
            ?>
        </div>
    </div>

    <script>
        jQuery(document).ready(function ($) {
            // Save settings handler
            $('#wpv-save-settings').on('click', function () {
                var $btn = $(this);
                var formData = {
                    action: 'wpv_save_optimization_settings',
                    nonce: wpVault.nonce,
                    compression_method: $('input[name="compression_method"]:checked').val(),
                    quality: $('#wpv-quality').val(),
                    output_format: $('#wpv-output-format').val(),
                    max_width: $('#wpv-max-width').val(),
                    max_height: $('#wpv-max-height').val(),
                    keep_original: $('#wpv-keep-original').is(':checked') ? 1 : 0,
                };

                $btn.prop('disabled', true).text('Saving...');

                $.post(wpVault.ajax_url, formData, function (response) {
                    if (response.success) {
                        alert('Settings saved successfully!');
                        // Update method option styling
                        $('.wpv-method-option').removeClass('selected');
                        $('input[name="compression_method"]:checked').closest('.wpv-method-option').addClass('selected');
                    } else {
                        alert('Error: ' + (response.data.message || 'Unknown error'));
                    }
                    $btn.prop('disabled', false).text('Save Settings');
                }).fail(function () {
                    alert('Network error. Please try again.');
                    $btn.prop('disabled', false).text('Save Settings');
                });
            });

            // Update method option styling on change
            $('input[name="compression_method"]').on('change', function () {
                $('.wpv-method-option').removeClass('selected');
                $(this).closest('.wpv-method-option').addClass('selected');
            });
        });
    </script>

    <!-- Optimization Modal -->
    <div id="wpv-optimize-modal" class="wpv-modal" style="display: none;">
        <div class="wpv-modal-overlay"></div>
        <div class="wpv-modal-content">
            <div class="wpv-modal-header">
                <h2><?php esc_html_e('Optimize Image', 'wp-vault'); ?></h2>
                <button type="button" class="wpv-modal-close" aria-label="<?php esc_attr_e('Close', 'wp-vault'); ?>">&times;</button>
            </div>
            <div class="wpv-modal-body">
                <div id="wpv-modal-error" class="notice notice-error" style="display: none; margin: 0 0 15px 0;">
                    <p></p>
                </div>
                <div id="wpv-modal-image-info" style="margin-bottom: 15px; padding: 10px; background: #f5f5f5; border-radius: 4px;">
                    <p><strong><?php esc_html_e('Current Size:', 'wp-vault'); ?></strong> <span id="wpv-modal-current-size">-</span></p>
                </div>
                <div class="wpv-modal-options">
                    <div style="margin-bottom: 15px;">
                        <label for="wpv-modal-method" style="display: block; margin-bottom: 5px; font-weight: 600;">
                            <?php esc_html_e('Compression Method', 'wp-vault'); ?>
                        </label>
                        <select id="wpv-modal-method" style="width: 100%; padding: 8px;">
                            <option value="php_native"><?php esc_html_e('Native PHP (Default)', 'wp-vault'); ?></option>
                            <option value="javascript"><?php esc_html_e('JavaScript (Client-side)', 'wp-vault'); ?></option>
                            <option value="server_side"><?php esc_html_e('Server-side (Best Results)', 'wp-vault'); ?></option>
                        </select>
                    </div>
                    <div style="margin-bottom: 15px;">
                        <label for="wpv-modal-format" style="display: block; margin-bottom: 5px; font-weight: 600;">
                            <?php esc_html_e('Output Format', 'wp-vault'); ?>
                        </label>
                        <select id="wpv-modal-format" style="width: 100%; padding: 8px;">
                            <option value="auto"><?php esc_html_e('Auto', 'wp-vault'); ?></option>
                            <option value="webp"><?php esc_html_e('WebP', 'wp-vault'); ?></option>
                            <option value="jpeg"><?php esc_html_e('JPEG', 'wp-vault'); ?></option>
                            <option value="png"><?php esc_html_e('PNG', 'wp-vault'); ?></option>
                            <option value="original"><?php esc_html_e('Original', 'wp-vault'); ?></option>
                        </select>
                    </div>
                    <div style="margin-bottom: 15px;">
                        <label for="wpv-modal-quality" style="display: block; margin-bottom: 5px; font-weight: 600;">
                            <?php esc_html_e('Quality:', 'wp-vault'); ?> <span id="wpv-modal-quality-value">80</span>%
                        </label>
                        <input type="range" id="wpv-modal-quality" min="1" max="100" value="80" style="width: 100%;">
                    </div>
                    <div style="margin-bottom: 15px;">
                        <label for="wpv-modal-max-width" style="display: block; margin-bottom: 5px; font-weight: 600;">
                            <?php esc_html_e('Max Width/Height:', 'wp-vault'); ?> <span id="wpv-modal-max-width-value">2048</span>px
                        </label>
                        <input type="range" id="wpv-modal-max-width" min="100" max="4096" value="2048" step="100" style="width: 100%;">
                    </div>
                    <div style="margin-bottom: 15px;">
                        <label style="display: flex; align-items: center; cursor: pointer;">
                            <input type="checkbox" id="wpv-modal-keep-original" checked style="margin-right: 8px;">
                            <span><?php esc_html_e('Keep Original File', 'wp-vault'); ?></span>
                        </label>
                    </div>
                </div>
                <div id="wpv-modal-progress" style="display: none; margin-top: 15px; text-align: center;">
                    <div class="spinner is-active" style="float: none; margin: 0 auto;"></div>
                    <p style="margin-top: 10px;"><?php esc_html_e('Processing...', 'wp-vault'); ?> <span id="wpv-modal-progress-value">0</span>%</p>
                </div>
            </div>
            <div class="wpv-modal-footer">
                <button type="button" class="button wpv-modal-cancel"><?php esc_html_e('Cancel', 'wp-vault'); ?></button>
                <button type="button" class="button button-primary wpv-modal-optimize"><?php esc_html_e('Optimize Now', 'wp-vault'); ?></button>
            </div>
        </div>
    </div>

    <!-- Show Difference Modal -->
    <div id="wpv-difference-modal" class="wpv-modal" style="display: none;">
        <div class="wpv-modal-overlay"></div>
        <div class="wpv-modal-content" style="max-width: 900px;">
            <div class="wpv-modal-header">
                <h2><?php esc_html_e('Image Comparison', 'wp-vault'); ?></h2>
                <button type="button" class="wpv-modal-close" aria-label="<?php esc_attr_e('Close', 'wp-vault'); ?>">&times;</button>
            </div>
            <div class="wpv-modal-body">
                <div id="wpv-difference-loading" style="text-align: center; padding: 40px;">
                    <div class="spinner is-active" style="float: none; margin: 0 auto;"></div>
                    <p><?php esc_html_e('Loading images...', 'wp-vault'); ?></p>
                </div>
                <div id="wpv-difference-content" style="display: none;">
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                        <div>
                            <h3 style="margin-top: 0; text-align: center;"><?php esc_html_e('Original', 'wp-vault'); ?></h3>
                            <div id="wpv-original-image-container" style="text-align: center; margin-bottom: 10px;">
                                <img id="wpv-original-image" src="" alt="Original" style="max-width: 100%; height: auto; border: 1px solid #ddd; border-radius: 4px;">
                            </div>
                            <div style="text-align: center; color: #666;">
                                <strong id="wpv-original-size">-</strong>
                            </div>
                        </div>
                        <div>
                            <h3 style="margin-top: 0; text-align: center;"><?php esc_html_e('Optimized', 'wp-vault'); ?></h3>
                            <div id="wpv-optimized-image-container" style="text-align: center; margin-bottom: 10px;">
                                <img id="wpv-optimized-image" src="" alt="Optimized" style="max-width: 100%; height: auto; border: 1px solid #ddd; border-radius: 4px;">
                            </div>
                            <div style="text-align: center; color: #46b450;">
                                <strong id="wpv-optimized-size">-</strong>
                            </div>
                        </div>
                    </div>
                    <div style="margin-top: 20px; padding: 15px; background: #f0f6fc; border-radius: 4px; text-align: center;">
                        <strong style="color: #2271b1;"><?php esc_html_e('Space Saved:', 'wp-vault'); ?></strong>
                        <span id="wpv-difference-saved" style="margin-left: 10px; font-size: 18px; color: #46b450;">-</span>
                    </div>
                </div>
            </div>
            <div class="wpv-modal-footer">
                <button type="button" class="button button-primary wpv-modal-close"><?php esc_html_e('Close', 'wp-vault'); ?></button>
            </div>
        </div>
    </div>

    <style>
        .wpv-modal {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: 100000;
        }
        .wpv-modal-overlay {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.7);
        }
        .wpv-modal-content {
            position: relative;
            width: 90%;
            max-width: 600px;
            max-height: 90vh;
            margin: 5vh auto;
            background: #fff;
            border-radius: 4px;
            box-shadow: 0 2px 20px rgba(0, 0, 0, 0.3);
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }
        .wpv-modal-header {
            padding: 20px;
            border-bottom: 1px solid #ddd;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .wpv-modal-header h2 {
            margin: 0;
            font-size: 18px;
        }
        .wpv-modal-close {
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            color: #666;
            padding: 0;
            width: 30px;
            height: 30px;
            line-height: 30px;
        }
        .wpv-modal-close:hover {
            color: #000;
        }
        .wpv-modal-body {
            padding: 20px;
            overflow-y: auto;
            flex: 1;
        }
        .wpv-modal-footer {
            padding: 15px 20px;
            border-top: 1px solid #ddd;
            text-align: right;
        }
        .wpv-modal-footer .button {
            margin-left: 10px;
        }
    </style>
    <?php
}

// Call the function to display the tab
wpvault_display_optimization_tab();
?>