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
    });

})(jQuery);
