<?php
/**
 * Media Optimizer Class
 * 
 * Handles image compression and optimization
 *
 * @package WP_Vault
 * @since 1.0.0
 */

namespace WP_Vault;

if (!defined('ABSPATH')) {
    exit;
}

class WP_Vault_Media_Optimizer
{
    /**
     * Check PHP capabilities for image optimization
     *
     * @return array
     */
    public static function check_php_capabilities()
    {
        $capabilities = array(
            'gd' => extension_loaded('gd'),
            'gd_webp' => function_exists('imagewebp'),
            'imagick' => extension_loaded('imagick'),
            'imagick_webp' => false,
            'zlib' => extension_loaded('zlib'),
            'can_optimize' => false,
            'can_convert_webp' => false,
        );

        // Check Imagick WebP support
        if ($capabilities['imagick']) {
            try {
                $imagick_formats = \Imagick::queryFormats();
                $capabilities['imagick_webp'] = in_array('WEBP', $imagick_formats);
            } catch (\Exception $e) {
                $capabilities['imagick_webp'] = false;
            }
        }

        // Determine overall capabilities
        $capabilities['can_optimize'] = $capabilities['gd'] || $capabilities['imagick'];
        $capabilities['can_convert_webp'] = $capabilities['gd_webp'] || $capabilities['imagick_webp'];

        return $capabilities;
    }

    /**
     * Optimize image using native PHP (GD or Imagick)
     *
     * @param int $attachment_id Attachment ID
     * @param array $options Optimization options
     * @return array|WP_Error
     */
    public static function optimize_with_php($attachment_id, $options = array())
    {
        $defaults = array(
            'quality' => 80,
            'output_format' => 'auto', // auto, webp, jpeg, png, original
            'max_width' => 2048,
            'max_height' => 2048,
            'keep_original' => true,
        );
        $options = wp_parse_args($options, $defaults);

        $file_path = get_attached_file($attachment_id);
        if (!file_exists($file_path)) {
            return new \WP_Error('file_not_found', __('Original file not found.', 'wp-vault'));
        }

        $original_size = filesize($file_path);
        $mime_type = get_post_mime_type($attachment_id);
        $original_mime = $mime_type;

        // Determine output format
        $output_format = $options['output_format'];
        if ($output_format === 'auto') {
            // Auto: convert PNG to WebP if supported, otherwise JPEG
            if ($mime_type === 'image/png' && self::check_php_capabilities()['can_convert_webp']) {
                $output_format = 'webp';
            } elseif ($mime_type === 'image/png') {
                $output_format = 'jpeg';
            } elseif ($mime_type === 'image/jpeg' && self::check_php_capabilities()['can_convert_webp']) {
                $output_format = 'webp';
            } else {
                $output_format = 'original';
            }
        }

        // Get image dimensions
        $image_info = wp_getimagesize($file_path);
        if (!$image_info) {
            return new \WP_Error('invalid_image', __('Invalid image file.', 'wp-vault'));
        }

        list($width, $height) = $image_info;

        // Calculate new dimensions if needed
        if ($width > $options['max_width'] || $height > $options['max_height']) {
            $ratio = min($options['max_width'] / $width, $options['max_height'] / $height);
            $new_width = (int) ($width * $ratio);
            $new_height = (int) ($height * $ratio);
        } else {
            $new_width = $width;
            $new_height = $height;
        }

        // Determine output MIME type
        $output_mime = $original_mime;
        $webp_converted = false;
        if ($output_format === 'webp') {
            $output_mime = 'image/webp';
            $webp_converted = true;
        } elseif ($output_format === 'jpeg') {
            $output_mime = 'image/jpeg';
        }

        // Create optimized image
        $capabilities = self::check_php_capabilities();
        $optimized_path = false;

        if ($capabilities['imagick'] && ($output_format === 'webp' ? $capabilities['imagick_webp'] : true)) {
            // Use Imagick
            try {
                $imagick = new \Imagick($file_path);
                $imagick->setImageCompressionQuality($options['quality']);

                if ($new_width !== $width || $new_height !== $height) {
                    $imagick->resizeImage($new_width, $new_height, \Imagick::FILTER_LANCZOS, 1);
                }

                if ($output_format === 'webp') {
                    $imagick->setImageFormat('webp');
                } elseif ($output_format === 'jpeg') {
                    $imagick->setImageFormat('jpeg');
                }

                // Save to temporary file
                $temp_file = wp_tempnam('wpv_optimized_');
                $imagick->writeImage($temp_file);
                $optimized_path = $temp_file;
                $imagick->clear();
                $imagick->destroy();
            } catch (\Exception $e) {
                return new \WP_Error('imagick_error', __('Imagick error: ', 'wp-vault') . $e->getMessage());
            }
        } elseif ($capabilities['gd']) {
            // Use GD
            $image = false;
            if ($mime_type === 'image/jpeg') {
                $image = imagecreatefromjpeg($file_path);
            } elseif ($mime_type === 'image/png') {
                $image = imagecreatefrompng($file_path);
            } elseif ($mime_type === 'image/gif') {
                $image = imagecreatefromgif($file_path);
            } elseif ($mime_type === 'image/webp' && function_exists('imagecreatefromwebp')) {
                $image = imagecreatefromwebp($file_path);
            }

            if (!$image) {
                return new \WP_Error('gd_error', __('Failed to load image with GD.', 'wp-vault'));
            }

            // Create resized image
            $new_image = imagecreatetruecolor($new_width, $new_height);
            if ($mime_type === 'image/png' || $mime_type === 'image/gif') {
                imagealphablending($new_image, false);
                imagesavealpha($new_image, true);
                $transparent = imagecolorallocatealpha($new_image, 255, 255, 255, 127);
                imagefilledrectangle($new_image, 0, 0, $new_width, $new_height, $transparent);
            }
            imagecopyresampled($new_image, $image, 0, 0, 0, 0, $new_width, $new_height, $width, $height);

            // Save optimized image
            $temp_file = wp_tempnam('wpv_optimized_');
            $saved = false;

            if ($output_format === 'webp' && $capabilities['gd_webp']) {
                $saved = imagewebp($new_image, $temp_file, $options['quality']);
            } elseif ($output_format === 'jpeg' || ($output_format === 'original' && $mime_type === 'image/jpeg')) {
                $saved = imagejpeg($new_image, $temp_file, $options['quality']);
            } elseif ($output_format === 'png' || ($output_format === 'original' && $mime_type === 'image/png')) {
                $saved = imagepng($new_image, $temp_file, 9);
            }

            imagedestroy($image);
            imagedestroy($new_image);

            if (!$saved) {
                return new \WP_Error('gd_save_error', __('Failed to save optimized image.', 'wp-vault'));
            }

            $optimized_path = $temp_file;
        } else {
            return new \WP_Error('no_library', __('No image library available (GD or Imagick required).', 'wp-vault'));
        }

        if (!$optimized_path || !file_exists($optimized_path)) {
            return new \WP_Error('optimization_failed', __('Failed to create optimized image.', 'wp-vault'));
        }

        $compressed_size = filesize($optimized_path);
        $space_saved = $original_size - $compressed_size;
        $compression_ratio = $original_size > 0 ? (($space_saved / $original_size) * 100) : 0;

        // Save optimized file as -min.extension
        $file_info = pathinfo($file_path);
        $optimized_filename = $file_info['filename'] . '-min.' . ($output_format === 'webp' ? 'webp' : ($output_format === 'jpeg' ? 'jpg' : ($output_format === 'png' ? 'png' : $file_info['extension'])));
        $optimized_file_path = $file_info['dirname'] . '/' . $optimized_filename;

        // Copy optimized file to -min.extension location
        if (!copy($optimized_path, $optimized_file_path)) {
            wp_delete_file($optimized_path);
            return new \WP_Error('save_failed', __('Failed to save optimized file.', 'wp-vault'));
        }
        wp_delete_file($optimized_path);

        // Create new attachment for optimized file if it doesn't exist
        $optimized_attachment_id = self::get_optimized_attachment_id($attachment_id);
        if (!$optimized_attachment_id) {
            // Create new attachment post for optimized file
            $attachment_data = array(
                'post_mime_type' => $output_mime,
                'post_title' => $file_info['filename'] . ' (Optimized)',
                'post_content' => '',
                'post_status' => 'inherit',
                'post_parent' => wp_get_post_parent_id($attachment_id),
            );
            $optimized_attachment_id = wp_insert_attachment($attachment_data, $optimized_file_path);

            if (!is_wp_error($optimized_attachment_id)) {
                // Generate attachment metadata
                $metadata = wp_generate_attachment_metadata($optimized_attachment_id, $optimized_file_path);
                wp_update_attachment_metadata($optimized_attachment_id, $metadata);

                // Store relationship in post meta
                update_post_meta($optimized_attachment_id, '_wpvault_original_attachment_id', $attachment_id);
                update_post_meta($attachment_id, '_wpvault_optimized_attachment_id', $optimized_attachment_id);
            }
        } else {
            // Update existing optimized attachment
            wp_update_post(array(
                'ID' => $optimized_attachment_id,
                'post_mime_type' => $output_mime,
            ));
            $metadata = wp_generate_attachment_metadata($optimized_attachment_id, $optimized_file_path);
            wp_update_attachment_metadata($optimized_attachment_id, $metadata);
        }

        // If not keeping original, replace the original file with optimized version
        if (!$options['keep_original']) {
            // Backup original
            $backup_path = $file_path . '.backup';
            if (file_exists($file_path) && !file_exists($backup_path)) {
                copy($file_path, $backup_path);
            }

            // Replace original with optimized
            copy($optimized_file_path, $file_path);

            // Update original attachment metadata
            $metadata = wp_generate_attachment_metadata($attachment_id, $file_path);
            wp_update_attachment_metadata($attachment_id, $metadata);

            // Update MIME type if changed
            if ($output_mime !== $original_mime) {
                wp_update_post(array(
                    'ID' => $attachment_id,
                    'post_mime_type' => $output_mime,
                ));
            }
        }

        // Save optimization record
        $record_data = array(
            'attachment_id' => $attachment_id,
            'original_size' => $original_size,
            'compressed_size' => $compressed_size,
            'compression_ratio' => round($compression_ratio, 2),
            'space_saved' => $space_saved,
            'compression_method' => 'php_native',
            'original_mime_type' => $original_mime,
            'output_mime_type' => $output_mime,
            'webp_converted' => $webp_converted ? 1 : 0,
            'status' => 'completed',
        );

        self::save_optimization_record($record_data);

        // Store optimized file path in post meta for easy retrieval
        update_post_meta($attachment_id, '_wpvault_optimized_file_path', $optimized_file_path);
        if ($optimized_attachment_id) {
            update_post_meta($attachment_id, '_wpvault_optimized_attachment_id', $optimized_attachment_id);
        }

        return array(
            'success' => true,
            'attachment_id' => $attachment_id,
            'optimized_attachment_id' => $optimized_attachment_id,
            'optimized_file_path' => $optimized_file_path,
            'original_size' => $original_size,
            'compressed_size' => $compressed_size,
            'space_saved' => $space_saved,
            'compression_ratio' => round($compression_ratio, 2),
            'webp_converted' => $webp_converted,
        );
    }

    /**
     * Optimize image using server-side (WPVault Cloud)
     *
     * @param int $attachment_id Attachment ID
     * @return array|WP_Error
     */
    public static function optimize_with_server($attachment_id)
    {
        // Check if connected to WPVault Cloud
        $site_id = get_option('wpv_site_id');
        if (!$site_id) {
            return new \WP_Error('not_connected', __('Not connected to WPVault Cloud.', 'wp-vault'));
        }

        $file_path = get_attached_file($attachment_id);
        if (!file_exists($file_path)) {
            return new \WP_Error('file_not_found', __('Original file not found.', 'wp-vault'));
        }

        // TODO: Implement API call to WPVault Cloud for optimization
        // For now, return error
        return new \WP_Error('not_implemented', __('Server-side optimization not yet implemented.', 'wp-vault'));
    }

    /**
     * Save optimization record to database
     *
     * @param array $data Record data
     * @return int|false Record ID or false on failure
     */
    public static function save_optimization_record($data)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'wp_vault_media_optimization';

        // Check if record exists
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$table} WHERE attachment_id = %d",
            $data['attachment_id']
        ));

        if ($existing) {
            // Update existing record
            $result = $wpdb->update(
                $table,
                $data,
                array('attachment_id' => $data['attachment_id']),
                array('%d', '%d', '%d', '%f', '%d', '%s', '%s', '%s', '%d', '%s'),
                array('%d')
            );
            return $existing->id;
        } else {
            // Insert new record
            $result = $wpdb->insert(
                $table,
                $data,
                array('%d', '%d', '%d', '%f', '%d', '%s', '%s', '%s', '%d', '%s')
            );
            return $result ? $wpdb->insert_id : false;
        }
    }

    /**
     * Get optimization status for an attachment
     *
     * @param int $attachment_id Attachment ID
     * @return array|null
     */
    public static function get_optimization_status($attachment_id)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'wp_vault_media_optimization';

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE attachment_id = %d",
            $attachment_id
        ), ARRAY_A);
    }

    /**
     * Get optimized attachment ID for an original attachment
     *
     * @param int $attachment_id Original attachment ID
     * @return int|false Optimized attachment ID or false
     */
    public static function get_optimized_attachment_id($attachment_id)
    {
        $optimized_id = get_post_meta($attachment_id, '_wpvault_optimized_attachment_id', true);
        if ($optimized_id && get_post($optimized_id)) {
            return (int) $optimized_id;
        }
        return false;
    }

    /**
     * Get optimized file path for an attachment
     *
     * @param int $attachment_id Attachment ID
     * @return string|false File path or false
     */
    public static function get_optimized_file_path($attachment_id)
    {
        $optimized_id = self::get_optimized_attachment_id($attachment_id);
        if ($optimized_id) {
            return get_attached_file($optimized_id);
        }

        // Fallback to post meta
        $path = get_post_meta($attachment_id, '_wpvault_optimized_file_path', true);
        if ($path && file_exists($path)) {
            return $path;
        }

        // Try to construct path from original
        $original_path = get_attached_file($attachment_id);
        if ($original_path) {
            $file_info = pathinfo($original_path);
            $possible_paths = array(
                $file_info['dirname'] . '/' . $file_info['filename'] . '-min.' . $file_info['extension'],
                $file_info['dirname'] . '/' . $file_info['filename'] . '-min.webp',
                $file_info['dirname'] . '/' . $file_info['filename'] . '-min.jpg',
            );
            foreach ($possible_paths as $path) {
                if (file_exists($path)) {
                    return $path;
                }
            }
        }

        return false;
    }

    /**
     * Get unoptimized media items
     *
     * @param int $limit Limit
     * @return array
     */
    public static function get_unoptimized_media($limit = 20)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'wp_vault_media_optimization';

        // Get all image attachments
        $attachments = get_posts(array(
            'post_type' => 'attachment',
            'post_mime_type' => 'image',
            'posts_per_page' => -1,
            'fields' => 'ids',
        ));

        if (empty($attachments)) {
            return array();
        }

        // Get optimized attachment IDs
        $optimized_ids = $wpdb->get_col("SELECT attachment_id FROM {$table} WHERE status = 'completed'");

        // Find unoptimized
        $unoptimized_ids = array_diff($attachments, $optimized_ids);
        $unoptimized_ids = array_slice($unoptimized_ids, 0, $limit);

        if (empty($unoptimized_ids)) {
            return array();
        }

        return get_posts(array(
            'post_type' => 'attachment',
            'post__in' => $unoptimized_ids,
            'posts_per_page' => $limit,
            'orderby' => 'date',
            'order' => 'DESC',
        ));
    }

    /**
     * Get optimization statistics
     *
     * @return array
     */
    public static function get_optimization_stats()
    {
        global $wpdb;
        $table = $wpdb->prefix . 'wp_vault_media_optimization';

        $stats = $wpdb->get_row(
            "SELECT 
                COUNT(*) as total_optimized,
                SUM(space_saved) as total_space_saved,
                AVG(compression_ratio) as avg_compression_ratio
            FROM {$table} 
            WHERE status = 'completed'",
            ARRAY_A
        );

        // Get total image count
        $total_images = wp_count_posts('attachment');
        $total_image_count = isset($total_images->inherit) ? $total_images->inherit : 0;

        // Filter to only images
        $image_attachments = get_posts(array(
            'post_type' => 'attachment',
            'post_mime_type' => 'image',
            'posts_per_page' => -1,
            'fields' => 'ids',
        ));
        $total_image_count = count($image_attachments);

        return array(
            'total_images' => $total_image_count,
            'optimized_count' => (int) ($stats['total_optimized'] ?? 0),
            'unoptimized_count' => max(0, $total_image_count - (int) ($stats['total_optimized'] ?? 0)),
            'total_space_saved' => (int) ($stats['total_space_saved'] ?? 0),
            'avg_compression_ratio' => round((float) ($stats['avg_compression_ratio'] ?? 0), 2),
        );
    }
}

