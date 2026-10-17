/**
 * WPCalibrate Loyalty & Rewards - Admin Scripts
 */
(function($) {
    'use strict';

    $(document).ready(function() {
        // Confirmation on destructive actions
        $('.button-link-delete, .wpclr-confirm-action').on('click', function(e) {
            var msg = $(this).data('confirm') || (window.wpclrAdmin && window.wpclrAdmin.i18n ? window.wpclrAdmin.i18n.confirmDelete : 'Are you sure?');
            if (!confirm(msg)) {
                e.preventDefault();
                return false;
            }
        });
    });
})(jQuery);
