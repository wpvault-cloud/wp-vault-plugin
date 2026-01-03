/**
 * Gutenberg Block Editor Optimizer
 * 
 * Adds WPVault Optimize button to Image block toolbar
 *
 * @package WP_Vault
 * @since 1.0.0
 */

// Immediate log to confirm script is loaded - this should always show
console.log('[WPVault] Gutenberg optimizer script file loaded');
console.log('[WPVault] Script location:', window.location.href);
console.log('[WPVault] Current time:', new Date().toISOString());

(function () {
    'use strict';

    try {
        console.log('[WPVault] Gutenberg optimizer script initializing...');

        // Wait for WordPress dependencies to load
        function initOptimizer() {
            console.log('[WPVault] Checking WordPress dependencies...');

            if (typeof wp === 'undefined') {
                console.error('[WPVault] wp object not found');
                return;
            }

            if (typeof wp.element === 'undefined') {
                console.error('[WPVault] wp.element not found');
                return;
            }

            if (typeof wp.blockEditor === 'undefined') {
                console.error('[WPVault] wp.blockEditor not found');
                return;
            }

            if (typeof wp.components === 'undefined') {
                console.error('[WPVault] wp.components not found');
                return;
            }

            if (typeof wp.hooks === 'undefined') {
                console.error('[WPVault] wp.hooks not found');
                return;
            }

            if (typeof wp.data === 'undefined') {
                console.error('[WPVault] wp.data not found');
                return;
            }

            console.log('[WPVault] All dependencies found, initializing...');

            var el = wp.element.createElement;
            var BlockControls = wp.blockEditor.BlockControls;
            var ToolbarButton = wp.components.ToolbarButton;
            var Modal = wp.components.Modal;
            var Button = wp.components.Button;
            var SelectControl = wp.components.SelectControl;
            var RangeControl = wp.components.RangeControl;
            var ToggleControl = wp.components.ToggleControl;
            var Spinner = wp.components.Spinner;
            var Notice = wp.components.Notice;
            var useState = wp.element.useState;
            var useSelect = wp.data.useSelect;

            // Check if wpVaultGutenberg is defined
            if (typeof wpVaultGutenberg === 'undefined') {
                console.error('[WPVault] wpVaultGutenberg object not found. Make sure the script is properly localized.');
                return;
            }

            console.log('[WPVault] wpVaultGutenberg found:', wpVaultGutenberg);

            /**
             * Optimization Modal Component
             */
            function OptimizationModal(props) {
                var isOpen = props.isOpen;
                var onClose = props.onClose;
                var attachmentId = props.attachmentId;
                var imageUrl = props.imageUrl;
                var imageSize = props.imageSize;

                var [isProcessing, setIsProcessing] = useState(false);
                var [progress, setProgress] = useState(0);
                var [error, setError] = useState(null);
                var [outputFormat, setOutputFormat] = useState('auto');
                var [quality, setQuality] = useState(80);
                var [maxWidth, setMaxWidth] = useState(2048);
                var [keepOriginal, setKeepOriginal] = useState(true);
                var [compressionMethod, setCompressionMethod] = useState('php_native');

                if (!isOpen) {
                    return null;
                }

                function handleOptimize() {
                    console.log('[WPVault] Starting optimization for attachment:', attachmentId);
                    setIsProcessing(true);
                    setError(null);
                    setProgress(0);

                    if (compressionMethod === 'javascript' && typeof imageCompression !== 'undefined') {
                        optimizeWithJavaScript();
                    } else {
                        optimizeWithPHP();
                    }
                }

                function optimizeWithJavaScript() {
                    console.log('[WPVault] Using JavaScript compression');
                    // Fetch image
                    fetch(imageUrl)
                        .then(function (response) {
                            if (!response.ok) {
                                throw new Error('Failed to fetch image');
                            }
                            return response.blob();
                        })
                        .then(function (blob) {
                            var options = {
                                maxSizeMB: 1,
                                maxWidthOrHeight: maxWidth,
                                useWebWorker: true,
                            };

                            if (outputFormat === 'webp') {
                                options.fileType = 'image/webp';
                            }

                            setProgress(30);
                            return imageCompression(blob, options);
                        })
                        .then(function (compressedBlob) {
                            setProgress(60);
                            // Upload compressed image
                            var formData = new FormData();
                            formData.append('action', 'wpv_optimize_single_image');
                            formData.append('nonce', wpVaultGutenberg.nonce);
                            formData.append('attachment_id', attachmentId);
                            formData.append('method', 'javascript');
                            formData.append('compressed_file', compressedBlob, 'optimized.' + (outputFormat === 'webp' ? 'webp' : 'jpg'));
                            formData.append('output_mime_type', outputFormat === 'webp' ? 'image/webp' : 'image/jpeg');
                            formData.append('webp_converted', outputFormat === 'webp' ? 1 : 0);

                            return fetch(wpVaultGutenberg.ajax_url, {
                                method: 'POST',
                                body: formData,
                            });
                        })
                        .then(function (response) {
                            if (!response.ok) {
                                throw new Error('Network response was not ok');
                            }
                            return response.json();
                        })
                        .then(function (data) {
                            console.log('[WPVault] Optimization response:', data);
                            setProgress(100);
                            if (data.success) {
                                showSuccessMessage(data.data);
                                setTimeout(function () {
                                    onClose();
                                    window.location.reload();
                                }, 1500);
                            } else {
                                setError(data.data.message || 'Optimization failed');
                                setIsProcessing(false);
                            }
                        })
                        .catch(function (err) {
                            console.error('[WPVault] JavaScript compression error:', err);
                            setError(err.message || 'Compression failed');
                            setIsProcessing(false);
                        });
                }

                function optimizeWithPHP() {
                    console.log('[WPVault] Using PHP compression, method:', compressionMethod);
                    var formData = new FormData();
                    formData.append('action', 'wpv_optimize_single_image');
                    formData.append('nonce', wpVaultGutenberg.nonce);
                    formData.append('attachment_id', attachmentId);
                    formData.append('method', compressionMethod);
                    formData.append('quality', quality);
                    formData.append('output_format', outputFormat);
                    formData.append('max_width', maxWidth);
                    formData.append('max_height', maxWidth);
                    formData.append('keep_original', keepOriginal ? 1 : 0);

                    fetch(wpVaultGutenberg.ajax_url, {
                        method: 'POST',
                        body: formData,
                    })
                        .then(function (response) {
                            if (!response.ok) {
                                throw new Error('Network response was not ok');
                            }
                            return response.json();
                        })
                        .then(function (data) {
                            console.log('[WPVault] Optimization response:', data);
                            if (data.success) {
                                setProgress(100);
                                showSuccessMessage(data.data);
                                setTimeout(function () {
                                    onClose();
                                    window.location.reload();
                                }, 1500);
                            } else {
                                setError(data.data.message || 'Optimization failed');
                                setIsProcessing(false);
                            }
                        })
                        .catch(function (err) {
                            console.error('[WPVault] PHP compression error:', err);
                            setError(err.message || 'Network error');
                            setIsProcessing(false);
                        });
                }

                function showSuccessMessage(data) {
                    var savedPercent = data.compression_ratio || 0;
                    var savedSize = formatBytes(data.space_saved || 0);
                    var message = 'Image optimized! You saved ' + savedSize + ' (' + savedPercent.toFixed(1) + '%)';

                    console.log('[WPVault] Success:', message);

                    // Use WordPress notices
                    if (typeof wp !== 'undefined' && wp.data && wp.data.dispatch) {
                        try {
                            wp.data.dispatch('core/notices').createSuccessNotice(message, {
                                type: 'snackbar',
                                isDismissible: true,
                            });
                        } catch (e) {
                            console.warn('[WPVault] Could not create notice:', e);
                            alert(message);
                        }
                    } else {
                        alert(message);
                    }
                }

                function formatBytes(bytes) {
                    if (bytes === 0) return '0 Bytes';
                    var k = 1024;
                    var sizes = ['Bytes', 'KB', 'MB', 'GB'];
                    var i = Math.floor(Math.log(bytes) / Math.log(k));
                    return Math.round(bytes / Math.pow(k, i) * 100) / 100 + ' ' + sizes[i];
                }

                return el(Modal, {
                    title: 'Optimize Image',
                    onRequestClose: onClose,
                    className: 'wpvault-optimization-modal',
                },
                    el('div', { className: 'wpvault-modal-content', style: { padding: '20px' } },
                        error && el(Notice, {
                            status: 'error',
                            isDismissible: true,
                            onRemove: function () { setError(null); }
                        }, error),

                        el('div', { className: 'wpvault-image-info', style: { marginBottom: '15px' } },
                            el('p', null, 'Current: ' + (imageSize ? formatBytes(imageSize) : 'Unknown size'))
                        ),

                        el('div', { className: 'wpvault-options' },
                            el(SelectControl, {
                                label: 'Compression Method',
                                value: compressionMethod,
                                options: [
                                    { label: 'Native PHP (Default)', value: 'php_native' },
                                    { label: 'JavaScript (Client-side)', value: 'javascript' },
                                    { label: 'Server-side (Best Results)', value: 'server_side' },
                                ],
                                onChange: setCompressionMethod,
                            }),

                            el(SelectControl, {
                                label: 'Output Format',
                                value: outputFormat,
                                options: [
                                    { label: 'Auto', value: 'auto' },
                                    { label: 'WebP', value: 'webp' },
                                    { label: 'JPEG', value: 'jpeg' },
                                    { label: 'PNG', value: 'png' },
                                    { label: 'Original', value: 'original' },
                                ],
                                onChange: setOutputFormat,
                            }),

                            el(RangeControl, {
                                label: 'Quality',
                                value: quality,
                                onChange: setQuality,
                                min: 1,
                                max: 100,
                            }),

                            el(RangeControl, {
                                label: 'Max Width/Height',
                                value: maxWidth,
                                onChange: setMaxWidth,
                                min: 100,
                                max: 4096,
                                step: 100,
                            }),

                            el(ToggleControl, {
                                label: 'Keep Original File',
                                checked: keepOriginal,
                                onChange: setKeepOriginal,
                            })
                        ),

                        isProcessing && el('div', { className: 'wpvault-progress', style: { marginTop: '15px', textAlign: 'center' } },
                            el(Spinner, null),
                            el('p', null, 'Processing... ' + progress + '%')
                        ),

                        el('div', { className: 'wpvault-modal-actions', style: { marginTop: '20px', textAlign: 'right' } },
                            el(Button, {
                                isSecondary: true,
                                onClick: onClose,
                                disabled: isProcessing,
                            }, 'Cancel'),
                            ' ',
                            el(Button, {
                                isPrimary: true,
                                onClick: handleOptimize,
                                disabled: isProcessing,
                            }, 'Optimize Now')
                        )
                    )
                );
            }

            /**
             * Filter to add toolbar button to Image block
             */
            wp.hooks.addFilter(
                'editor.BlockEdit',
                'wpvault/image-optimizer',
                function (BlockEdit) {
                    return function (props) {
                        try {
                            // Only process Image blocks
                            if (props.name !== 'core/image') {
                                return el(BlockEdit, props);
                            }

                            var attachmentId = props.attributes.id;
                            var imageUrl = props.attributes.url;

                            // Only show button if we have an attachment ID
                            if (!attachmentId) {
                                console.log('[WPVault] Image block has no attachment ID, skipping');
                                return el(BlockEdit, props);
                            }

                            console.log('[WPVault] Adding optimize button to image block, attachment ID:', attachmentId);

                            // Use a wrapper component to manage modal state
                            var ImageBlockWithOptimizer = function () {
                                var [isModalOpen, setIsModalOpen] = useState(false);

                                return el('div', null,
                                    el(BlockEdit, props),
                                    el(BlockControls, null,
                                        el(ToolbarButton, {
                                            icon: el('svg', {
                                                width: '20',
                                                height: '20',
                                                viewBox: '0 0 24 24',
                                                fill: 'currentColor',
                                                xmlns: 'http://www.w3.org/2000/svg',
                                                style: { display: 'block', width: '20px', height: '20px' }
                                            },
                                                el('path', {
                                                    d: 'M11 21h-1l1-7H7.5c-.88 0-.33-.75-.31-.78L11.5 3h1l-1 7h3.5c.49 0 .56.33.47.51l-4.46 10.49z',
                                                    fill: 'currentColor'
                                                })
                                            ),
                                            label: 'WPVault Optimize',
                                            onClick: function () {
                                                console.log('[WPVault] Optimize button clicked');
                                                setIsModalOpen(true);
                                            },
                                        })
                                    ),
                                    isModalOpen && el(OptimizationModal, {
                                        isOpen: isModalOpen,
                                        onClose: function () {
                                            setIsModalOpen(false);
                                        },
                                        attachmentId: attachmentId,
                                        imageUrl: imageUrl,
                                        imageSize: null,
                                    })
                                );
                            };

                            return el(ImageBlockWithOptimizer);
                        } catch (error) {
                            console.error('[WPVault] Error in BlockEdit filter:', error);
                            // Return original block on error
                            return el(BlockEdit, props);
                        }
                    };
                }
            );

            console.log('[WPVault] Gutenberg optimizer initialized successfully');
        }

        // Wait for DOM and WordPress to be ready
        function tryInit() {
            if (typeof wp !== 'undefined' &&
                typeof wp.element !== 'undefined' &&
                typeof wp.blockEditor !== 'undefined' &&
                typeof wp.components !== 'undefined' &&
                typeof wp.hooks !== 'undefined' &&
                typeof wp.data !== 'undefined') {
                console.log('[WPVault] All dependencies ready, initializing...');
                initOptimizer();
                return true;
            }
            return false;
        }

        // Try immediately if already loaded
        if (tryInit()) {
            console.log('[WPVault] Initialized immediately');
        } else {
            console.log('[WPVault] Waiting for dependencies...');

            // Try on DOM ready
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', function () {
                    setTimeout(function () {
                        if (!tryInit()) {
                            console.warn('[WPVault] Dependencies not ready after DOMContentLoaded');
                        }
                    }, 500);
                });
            } else {
                setTimeout(function () {
                    if (!tryInit()) {
                        console.warn('[WPVault] Dependencies not ready, will retry on window load');
                    }
                }, 500);
            }

            // Also try on window load as fallback
            window.addEventListener('load', function () {
                setTimeout(function () {
                    if (!tryInit()) {
                        console.error('[WPVault] Failed to initialize after window load. WordPress dependencies may not be available.');
                    }
                }, 1000);
            });
        }
    } catch (error) {
        console.error('[WPVault] Fatal error in Gutenberg optimizer:', error);
        console.error('[WPVault] Error stack:', error.stack);
    }

})();
