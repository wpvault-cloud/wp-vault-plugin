/* WP Vault Admin JS */
(function ($) {
    'use strict';

    $(document).ready(function () {
        console.log('WP Vault Admin initialized');

        // Tab navigation (if not using URL-based tabs)
        $('.wpv-tab').on('click', function (e) {
            // Let the link navigate naturally (URL-based tabs)
            // This is just for any additional tab functionality if needed
        });

        // Ensure modals close on outside click
        $(document).on('click', function (e) {
            // Modal close handlers are in dashboard.php inline scripts
        });

        // Connection check handler
        $('#wpv-recheck-connection').on('click', function (e) {
            e.preventDefault();
            var $button = $(this);
            var $indicator = $('#wpv-connection-status-indicator');
            var $statusText = $('#wpv-connection-status-text');
            var $lastCheck = $('#wpv-last-check');
            var $lastSync = $('#wpv-last-sync');
            
            // Disable button and show loading state
            $button.prop('disabled', true);
            var $icon = $button.find('.dashicons');
            $icon.addClass('spin');
            
            // Update status text
            var $statusSpan = $('#wpv-connection-status-text');
            $statusSpan.text('Checking...');
            
            // Make AJAX request
            $.ajax({
                url: wpVault.ajax_url,
                type: 'POST',
                data: {
                    action: 'wpv_check_connection',
                    nonce: wpVault.nonce,
                    force: 'true' // Force check even if recently checked
                },
                success: function (response) {
                    if (response.success && response.data) {
                        var isConnected = response.data.connected === true;
                        var status = response.data.status || 'unknown';
                        
                        // Update status indicator
                        if (isConnected) {
                            $indicator.removeClass('wpv-status-disconnected').addClass('wpv-status-connected');
                            $statusSpan.text('Connected');
                        } else {
                            $indicator.removeClass('wpv-status-connected').addClass('wpv-status-disconnected');
                            $statusSpan.text('Disconnected');
                        }
                        
                        // Update last check time
                        if (response.data.last_check) {
                            if ($lastCheck.length) {
                                $lastCheck.text('Just now');
                            } else {
                                // Create last check row if it doesn't exist
                                var $lastSyncRow = $('.wpv-info-row').has('#wpv-last-sync');
                                if ($lastSyncRow.length) {
                                    $lastSyncRow.after(
                                        '<div class="wpv-info-row">' +
                                        '<span class="wpv-info-label">Last Check:</span>' +
                                        '<span class="wpv-info-value" id="wpv-last-check">Just now</span>' +
                                        '</div>'
                                    );
                                }
                            }
                        }
                        
                        // Show success/error message
                        if (isConnected) {
                            // You could show a toast notification here if you have one
                            console.log('Connection check successful');
                        } else {
                            var errorMsg = response.data.error || 'Connection failed';
                            alert('Connection check failed: ' + errorMsg);
                        }
                    } else {
                        // Error response
                        var errorMsg = response.data && response.data.error ? response.data.error : 'Unknown error';
                        $indicator.removeClass('wpv-status-connected').addClass('wpv-status-disconnected');
                        $statusSpan.text('Disconnected');
                        alert('Connection check failed: ' + errorMsg);
                    }
                },
                error: function (xhr, status, error) {
                    console.error('Connection check error:', error);
                    $indicator.removeClass('wpv-status-connected').addClass('wpv-status-disconnected');
                    $('#wpv-connection-status-text').text('Disconnected');
                    alert('Connection check failed: ' + error);
                },
                complete: function () {
                    // Re-enable button
                    $button.prop('disabled', false);
                    $icon.removeClass('spin');
                }
            });
        });
    });

    // Add spin animation for loading state
    if (!$('style#wpv-spin-animation').length) {
        $('head').append(
            '<style id="wpv-spin-animation">' +
            '.dashicons.spin { animation: wpv-spin 1s linear infinite; }' +
            '@keyframes wpv-spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }' +
            '</style>'
        );
    }

})(jQuery);
