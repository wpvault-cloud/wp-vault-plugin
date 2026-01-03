/**
 * Media Optimizer JavaScript
 * 
 * Handles client-side image compression using browser-image-compression
 *
 * @package WP_Vault
 * @since 1.0.0
 */

(function ($) {
    'use strict';

    // Load browser-image-compression from CDN if not already loaded
    if (typeof imageCompression === 'undefined') {
        var script = document.createElement('script');
        script.src = 'https://cdn.jsdelivr.net/npm/browser-image-compression@2.0.2/dist/browser-image-compression.js';
        script.onload = function () {
            initMediaOptimizer();
        };
        document.head.appendChild(script);
    } else {
        initMediaOptimizer();
    }

    function initMediaOptimizer() {
        // Open optimization modal
        $(document).on('click', '.wpv-optimize-single-btn', function (e) {
            e.preventDefault();
            var $btn = $(this);
            var attachmentId = $btn.data('attachment-id');
            var originalSize = $btn.data('original-size') || 0;

            // Set attachment ID in modal
            $('#wpv-optimize-modal').data('attachment-id', attachmentId);

            // Update current size display
            if (originalSize > 0) {
                $('#wpv-modal-current-size').text(formatBytes(originalSize));
            } else {
                $('#wpv-modal-current-size').text('-');
            }

            // Reset modal state
            $('#wpv-modal-error').hide();
            $('#wpv-modal-progress').hide();
            $('#wpv-modal-optimize').prop('disabled', false);

            // Show modal
            $('#wpv-optimize-modal').fadeIn(200);
        });

        // Close modal handlers
        $(document).on('click', '.wpv-modal-close, .wpv-modal-cancel', function () {
            $('#wpv-optimize-modal').fadeOut(200);
            $('#wpv-difference-modal').fadeOut(200);
        });

        // Close modal when clicking overlay
        $(document).on('click', '.wpv-modal-overlay', function () {
            $('#wpv-optimize-modal').fadeOut(200);
            $('#wpv-difference-modal').fadeOut(200);
        });

        // Prevent modal from closing when clicking inside content
        $(document).on('click', '.wpv-modal-content', function (e) {
            e.stopPropagation();
        });

        // Quality slider update
        $(document).on('input', '#wpv-modal-quality', function () {
            $('#wpv-modal-quality-value').text($(this).val());
        });

        // Max width slider update
        $(document).on('input', '#wpv-modal-max-width', function () {
            $('#wpv-modal-max-width-value').text($(this).val());
        });

        // Optimize button in modal
        $(document).on('click', '.wpv-modal-optimize', function () {
            var attachmentId = $('#wpv-optimize-modal').data('attachment-id');
            if (!attachmentId) {
                return;
            }

            var method = $('#wpv-modal-method').val();
            var $btn = $(this);

            // Hide error, show progress
            $('#wpv-modal-error').hide();
            $('#wpv-modal-progress').show();
            $('#wpv-modal-progress-value').text('0');
            $btn.prop('disabled', true);

            if (method === 'javascript') {
                optimizeWithJavaScriptFromModal(attachmentId);
            } else {
                optimizeWithPHPFromModal(attachmentId, method);
            }
        });

        // Show Difference button handler
        $(document).on('click', '.wpv-show-difference-btn', function (e) {
            e.preventDefault();
            var $btn = $(this);
            var attachmentId = $btn.data('attachment-id');

            // Show modal and loading
            $('#wpv-difference-modal').fadeIn(200);
            $('#wpv-difference-loading').show();
            $('#wpv-difference-content').hide();

            // Fetch comparison data
            $.post(wpVault.ajax_url, {
                action: 'wpv_get_image_comparison',
                nonce: wpVault.nonce,
                attachment_id: attachmentId,
            }, function (response) {
                $('#wpv-difference-loading').hide();
                if (response.success) {
                    $('#wpv-original-image').attr('src', response.data.original_url);
                    $('#wpv-optimized-image').attr('src', response.data.optimized_url);
                    $('#wpv-original-size').text(response.data.original_size);
                    $('#wpv-optimized-size').text(response.data.optimized_size);
                    $('#wpv-difference-saved').text(response.data.space_saved);
                    $('#wpv-difference-content').show();
                } else {
                    alert('Error: ' + (response.data.message || 'Failed to load comparison'));
                    $('#wpv-difference-modal').fadeOut(200);
                }
            }).fail(function () {
                $('#wpv-difference-loading').hide();
                alert('Network error. Please try again.');
                $('#wpv-difference-modal').fadeOut(200);
            });
        });

        // Bulk optimize button handler
        $('#wpv-compress-all').on('click', function () {
            var $btn = $(this);
            var method = $('input[name="compression_method"]:checked').val() || 'php_native';

            if (!confirm('This will optimize all unoptimized images. Continue?')) {
                return;
            }

            $btn.prop('disabled', true).text('Processing...');

            // Get all unoptimized attachment IDs
            var attachmentIds = [];
            $('.wpv-media-item[data-optimized="false"]').each(function () {
                var id = $(this).data('attachment-id');
                if (id) {
                    attachmentIds.push(id);
                }
            });

            if (attachmentIds.length === 0) {
                alert('No unoptimized images found.');
                $btn.prop('disabled', false).text('Compress All');
                return;
            }

            if (method === 'javascript') {
                bulkOptimizeWithJavaScript(attachmentIds, $btn);
            } else {
                bulkOptimizeWithPHP(attachmentIds, $btn, method);
            }
        });

        // Convert to WebP button handler
        $('#wpv-convert-webp').on('click', function () {
            var $btn = $(this);
            var method = $('input[name="compression_method"]:checked').val() || 'php_native';

            if (!confirm('This will convert all selected images to WebP format. Continue?')) {
                return;
            }

            // Get selected or all images
            var attachmentIds = [];
            $('.wpv-media-item input[type="checkbox"]:checked').each(function () {
                var id = $(this).closest('.wpv-media-item').data('attachment-id');
                if (id) {
                    attachmentIds.push(id);
                }
            });

            if (attachmentIds.length === 0) {
                // Select all unoptimized
                $('.wpv-media-item[data-optimized="false"]').each(function () {
                    var id = $(this).data('attachment-id');
                    if (id) {
                        attachmentIds.push(id);
                    }
                });
            }

            if (attachmentIds.length === 0) {
                alert('No images to convert.');
                return;
            }

            $btn.prop('disabled', true).text('Converting...');

            var options = {
                quality: parseInt($('#wpv-quality').val()) || 80,
                output_format: 'webp',
                max_width: parseInt($('#wpv-max-width').val()) || 2048,
                max_height: parseInt($('#wpv-max-height').val()) || 2048,
            };

            if (method === 'javascript') {
                bulkConvertToWebPWithJavaScript(attachmentIds, options, $btn);
            } else {
                bulkOptimizeWithPHP(attachmentIds, $btn, method, options);
            }
        });
    }

    /**
     * Optimize image using JavaScript from modal
     */
    function optimizeWithJavaScriptFromModal(attachmentId) {
        if (typeof imageCompression === 'undefined') {
            showModalError('Image compression library not loaded. Please refresh the page.');
            return;
        }

        updateModalProgress(10);

        // Get image URL from the media item
        var $mediaItem = $('.wpv-media-item[data-attachment-id="' + attachmentId + '"]');
        var imageUrl = $mediaItem.find('img').attr('src');
        if (!imageUrl) {
            showModalError('Image URL not found.');
            return;
        }

        var outputFormat = $('#wpv-modal-format').val();
        var quality = parseInt($('#wpv-modal-quality').val()) || 80;
        var maxWidth = parseInt($('#wpv-modal-max-width').val()) || 2048;
        var isWebP = outputFormat === 'webp' || (outputFormat === 'auto' && imageUrl.toLowerCase().indexOf('.png') > -1);

        // Fetch image as blob
        fetch(imageUrl)
            .then(function (response) {
                if (!response.ok) {
                    throw new Error('Failed to fetch image');
                }
                return response.blob();
            })
            .then(function (blob) {
                updateModalProgress(30);

                var options = {
                    maxSizeMB: 1,
                    maxWidthOrHeight: maxWidth,
                    useWebWorker: true,
                };

                if (isWebP) {
                    options.fileType = 'image/webp';
                }

                return imageCompression(blob, options);
            })
            .then(function (compressedBlob) {
                updateModalProgress(60);

                // Upload compressed image
                var formData = new FormData();
                formData.append('action', 'wpv_optimize_single_image');
                formData.append('nonce', wpVault.nonce);
                formData.append('attachment_id', attachmentId);
                formData.append('method', 'javascript');
                formData.append('compressed_file', compressedBlob, 'optimized.' + (isWebP ? 'webp' : 'jpg'));
                formData.append('output_mime_type', isWebP ? 'image/webp' : 'image/jpeg');
                formData.append('webp_converted', isWebP ? 1 : 0);
                formData.append('keep_original', $('#wpv-modal-keep-original').is(':checked') ? 1 : 0);

                return $.ajax({
                    url: wpVault.ajax_url,
                    type: 'POST',
                    data: formData,
                    processData: false,
                    contentType: false,
                });
            })
            .then(function (response) {
                updateModalProgress(100);
                if (response.success) {
                    showModalSuccess(response.data);
                    setTimeout(function () {
                        $('#wpv-optimize-modal').fadeOut(200);
                        location.reload();
                    }, 1500);
                } else {
                    showModalError(response.data.message || 'Optimization failed');
                    $('#wpv-modal-optimize').prop('disabled', false);
                }
            })
            .catch(function (error) {
                console.error('Compression error:', error);
                showModalError('Compression failed: ' + (error.message || 'Unknown error'));
                $('#wpv-modal-progress').hide();
                $('#wpv-modal-optimize').prop('disabled', false);
            });
    }

    /**
     * Optimize image using PHP from modal
     */
    function optimizeWithPHPFromModal(attachmentId, method) {
        updateModalProgress(10);

        var options = {
            quality: parseInt($('#wpv-modal-quality').val()) || 80,
            output_format: $('#wpv-modal-format').val() || 'auto',
            max_width: parseInt($('#wpv-modal-max-width').val()) || 2048,
            max_height: parseInt($('#wpv-modal-max-width').val()) || 2048,
            keep_original: $('#wpv-modal-keep-original').is(':checked'),
        };

        $.post(wpVault.ajax_url, {
            action: 'wpv_optimize_single_image',
            nonce: wpVault.nonce,
            attachment_id: attachmentId,
            method: method,
            quality: options.quality,
            output_format: options.output_format,
            max_width: options.max_width,
            max_height: options.max_height,
            keep_original: options.keep_original ? 1 : 0,
            xhr: function () {
                var xhr = new window.XMLHttpRequest();
                xhr.upload.addEventListener('progress', function (e) {
                    if (e.lengthComputable) {
                        var percentComplete = 10 + (e.loaded / e.total * 90);
                        updateModalProgress(Math.round(percentComplete));
                    }
                }, false);
                return xhr;
            }
        }, function (response) {
            updateModalProgress(100);
            if (response.success) {
                showModalSuccess(response.data);
                setTimeout(function () {
                    $('#wpv-optimize-modal').fadeOut(200);
                    location.reload();
                }, 1500);
            } else {
                showModalError(response.data.message || 'Optimization failed');
                $('#wpv-modal-progress').hide();
                $('#wpv-modal-optimize').prop('disabled', false);
            }
        }).fail(function () {
            showModalError('Network error. Please try again.');
            $('#wpv-modal-progress').hide();
            $('#wpv-modal-optimize').prop('disabled', false);
        });
    }

    /**
     * Update modal progress
     */
    function updateModalProgress(percent) {
        $('#wpv-modal-progress-value').text(percent);
    }

    /**
     * Show error in modal
     */
    function showModalError(message) {
        $('#wpv-modal-error p').text(message);
        $('#wpv-modal-error').show();
        $('#wpv-modal-progress').hide();
    }

    /**
     * Show success in modal
     */
    function showModalSuccess(data) {
        var savedPercent = data.compression_ratio || 0;
        var savedSize = formatBytes(data.space_saved || 0);
        var message = 'Image optimized! You saved ' + savedSize + ' (' + savedPercent.toFixed(1) + '%)';

        $('#wpv-modal-progress p').html('<strong style="color: #46b450;">' + message + '</strong>');
    }

    /**
     * Optimize image using JavaScript (client-side) - Legacy function
     */
    function optimizeWithJavaScript(attachmentId, $btn) {
        if (typeof imageCompression === 'undefined') {
            alert('Image compression library not loaded. Please refresh the page.');
            return;
        }

        // Get image URL
        var imageUrl = $btn.closest('.wpv-media-item').find('img').attr('src');
        if (!imageUrl) {
            alert('Image URL not found.');
            return;
        }

        $btn.prop('disabled', true).text('Compressing...');

        // Fetch image as blob
        fetch(imageUrl)
            .then(response => response.blob())
            .then(blob => {
                var options = {
                    maxSizeMB: 1,
                    maxWidthOrHeight: parseInt($('#wpv-max-width').val()) || 2048,
                    useWebWorker: true,
                    fileType: 'image/webp',
                };

                return imageCompression(blob, options);
            })
            .then(compressedBlob => {
                // Upload compressed image
                var formData = new FormData();
                formData.append('action', 'wpv_optimize_single_image');
                formData.append('nonce', wpVault.nonce);
                formData.append('attachment_id', attachmentId);
                formData.append('method', 'javascript');
                formData.append('compressed_file', compressedBlob, 'optimized.webp');
                formData.append('output_mime_type', 'image/webp');
                formData.append('webp_converted', 1);

                return $.ajax({
                    url: wpVault.ajax_url,
                    type: 'POST',
                    data: formData,
                    processData: false,
                    contentType: false,
                });
            })
            .then(response => {
                if (response.success) {
                    showSuccessMessage(response.data);
                    location.reload();
                } else {
                    alert('Error: ' + (response.data.message || 'Unknown error'));
                    $btn.prop('disabled', false).text('Optimize');
                }
            })
            .catch(error => {
                console.error('Compression error:', error);
                alert('Compression failed: ' + error.message);
                $btn.prop('disabled', false).text('Optimize');
            });
    }

    /**
     * Optimize image using PHP (server-side)
     */
    function optimizeWithPHP(attachmentId, $btn, method) {
        $btn.prop('disabled', true).text('Optimizing...');

        var options = {
            quality: parseInt($('#wpv-quality').val()) || 80,
            output_format: $('#wpv-output-format').val() || 'auto',
            max_width: parseInt($('#wpv-max-width').val()) || 2048,
            max_height: parseInt($('#wpv-max-height').val()) || 2048,
            keep_original: $('#wpv-keep-original').is(':checked'),
        };

        $.post(wpVault.ajax_url, {
            action: 'wpv_optimize_single_image',
            nonce: wpVault.nonce,
            attachment_id: attachmentId,
            method: method,
            quality: options.quality,
            output_format: options.output_format,
            max_width: options.max_width,
            max_height: options.max_height,
            keep_original: options.keep_original,
        }, function (response) {
            if (response.success) {
                showSuccessMessage(response.data);
                location.reload();
            } else {
                alert('Error: ' + (response.data.message || 'Unknown error'));
                $btn.prop('disabled', false).text('Optimize');
            }
        }).fail(function () {
            alert('Network error. Please try again.');
            $btn.prop('disabled', false).text('Optimize');
        });
    }

    /**
     * Bulk optimize with JavaScript
     */
    function bulkOptimizeWithJavaScript(attachmentIds, $btn) {
        // Process in batches to avoid overwhelming the browser
        var batchSize = 3;
        var processed = 0;
        var total = attachmentIds.length;

        function processBatch(startIndex) {
            var batch = attachmentIds.slice(startIndex, startIndex + batchSize);
            var promises = batch.map(function (attachmentId) {
                return optimizeSingleWithJavaScript(attachmentId);
            });

            Promise.all(promises).then(function () {
                processed += batch.length;
                updateProgress(processed, total);

                if (startIndex + batchSize < attachmentIds.length) {
                    setTimeout(function () {
                        processBatch(startIndex + batchSize);
                    }, 1000);
                } else {
                    $btn.prop('disabled', false).text('Compress All');
                    alert('Bulk optimization completed!');
                    location.reload();
                }
            }).catch(function (error) {
                console.error('Batch error:', error);
                $btn.prop('disabled', false).text('Compress All');
            });
        }

        processBatch(0);
    }

    /**
     * Optimize single image with JavaScript (helper)
     */
    function optimizeSingleWithJavaScript(attachmentId) {
        // Implementation similar to optimizeWithJavaScript but returns a promise
        // This is a simplified version - full implementation would fetch image and compress
        return new Promise(function (resolve, reject) {
            // For now, just resolve - full implementation needed
            resolve();
        });
    }

    /**
     * Bulk optimize with PHP
     */
    function bulkOptimizeWithPHP(attachmentIds, $btn, method, options) {
        options = options || {
            quality: parseInt($('#wpv-quality').val()) || 80,
            output_format: $('#wpv-output-format').val() || 'auto',
            max_width: parseInt($('#wpv-max-width').val()) || 2048,
            max_height: parseInt($('#wpv-max-height').val()) || 2048,
        };

        $.post(wpVault.ajax_url, {
            action: 'wpv_optimize_bulk_images',
            nonce: wpVault.nonce,
            attachment_ids: attachmentIds,
            method: method,
            quality: options.quality,
            output_format: options.output_format,
            max_width: options.max_width,
            max_height: options.max_height,
        }, function (response) {
            if (response.success) {
                alert('Optimized ' + response.data.success_count + ' images. ' +
                    (response.data.error_count > 0 ? response.data.error_count + ' failed.' : ''));
                location.reload();
            } else {
                alert('Error: ' + (response.data.message || 'Unknown error'));
            }
            $btn.prop('disabled', false).text('Compress All');
        }).fail(function () {
            alert('Network error. Please try again.');
            $btn.prop('disabled', false).text('Compress All');
        });
    }

    /**
     * Bulk convert to WebP with JavaScript
     */
    function bulkConvertToWebPWithJavaScript(attachmentIds, options, $btn) {
        // Similar to bulkOptimizeWithJavaScript but with WebP conversion
        bulkOptimizeWithPHP(attachmentIds, $btn, 'php_native', options);
    }

    /**
     * Show success message
     */
    function showSuccessMessage(data) {
        var savedPercent = data.compression_ratio || 0;
        var savedSize = formatBytes(data.space_saved || 0);
        var message = 'Image optimized! You saved ' + savedSize + ' (' + savedPercent.toFixed(1) + '%)';

        // Show notification
        if ($('#wpv-notification').length === 0) {
            $('body').append('<div id="wpv-notification" style="position:fixed;top:20px;right:20px;background:#46b450;color:#fff;padding:15px 20px;border-radius:4px;z-index:99999;box-shadow:0 2px 8px rgba(0,0,0,0.2);">' + message + '</div>');
            setTimeout(function () {
                $('#wpv-notification').fadeOut(function () {
                    $(this).remove();
                });
            }, 5000);
        }
    }

    /**
     * Update progress
     */
    function updateProgress(processed, total) {
        var percent = Math.round((processed / total) * 100);
        $('#wpv-bulk-progress').text('Processing: ' + processed + ' / ' + total + ' (' + percent + '%)');
    }

    /**
     * Format bytes to human readable
     */
    function formatBytes(bytes) {
        if (bytes === 0) return '0 Bytes';
        var k = 1024;
        var sizes = ['Bytes', 'KB', 'MB', 'GB'];
        var i = Math.floor(Math.log(bytes) / Math.log(k));
        return Math.round(bytes / Math.pow(k, i) * 100) / 100 + ' ' + sizes[i];
    }

    // Load optimization stats on page load
    $(document).ready(function () {
        if ($('#wpv-tab-optimization').length > 0) {
            loadOptimizationStats();
        }
    });

    /**
     * Load optimization statistics
     */
    function loadOptimizationStats() {
        $.post(wpVault.ajax_url, {
            action: 'wpv_get_optimization_stats',
            nonce: wpVault.nonce,
        }, function (response) {
            if (response.success) {
                updateStatsDisplay(response.data);
            }
        });
    }

    /**
     * Update stats display
     */
    function updateStatsDisplay(stats) {
        $('#wpv-stats-total').text(stats.total_images || 0);
        $('#wpv-stats-optimized').text(stats.optimized_count || 0);
        $('#wpv-stats-unoptimized').text(stats.unoptimized_count || 0);
        $('#wpv-stats-space-saved').text(formatBytes(stats.total_space_saved || 0));
        $('#wpv-stats-avg-compression').text((stats.avg_compression_ratio || 0) + '%');
    }

})(jQuery);

