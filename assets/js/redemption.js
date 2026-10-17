/**
 * WPCalibrate Loyalty & Rewards - Frontend Classic Redemption
 */
(function($) {
    'use strict';

    $(document).ready(function() {
        var $wrap = $('#wpclr-redemption-box');
        if (!$wrap.length) {
            return;
        }

        var config = window.wpclrRedemption || {};

        // Apply Points
        $wrap.on('click', '#wpclr-btn-apply', function(e) {
            e.preventDefault();
            var $btn = $(this);
            var $input = $('#wpclr-points-input');
            var $msg = $('#wpclr-msg-area');
            var points = parseInt($input.val(), 10);

            if (isNaN(points) || points <= 0) {
                $msg.html('<span class="wpclr-msg-error">Please enter a valid points amount.</span>');
                return;
            }

            $btn.prop('disabled', true).text(config.i18n ? config.i18n.applying : 'Applying...');
            $msg.empty();

            $.ajax({
                url: config.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'wpclr_apply_points',
                    nonce: config.nonce,
                    points: points
                },
                success: function(res) {
                    if (res && res.success) {
                        // Trigger WooCommerce cart/checkout update
                        $(document.body).trigger('update_checkout');
                        $(document.body).trigger('wc_update_cart');
                        location.reload();
                    } else {
                        $btn.prop('disabled', false).text('Apply Discount');
                        $msg.html('<span class="wpclr-msg-error">' + (res.data ? res.data.message : config.i18n.error) + '</span>');
                    }
                },
                error: function() {
                    $btn.prop('disabled', false).text('Apply Discount');
                    $msg.html('<span class="wpclr-msg-error">' + config.i18n.error + '</span>');
                }
            });
        });

        // Remove Points
        $wrap.on('click', '#wpclr-btn-remove', function(e) {
            e.preventDefault();
            var $btn = $(this);
            $btn.prop('disabled', true).text(config.i18n ? config.i18n.removing : 'Removing...');

            $.ajax({
                url: config.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'wpclr_remove_points',
                    nonce: config.nonce
                },
                success: function(res) {
                    $(document.body).trigger('update_checkout');
                    $(document.body).trigger('wc_update_cart');
                    location.reload();
                },
                error: function() {
                    $btn.prop('disabled', false).text('Remove Points');
                }
            });
        });
    });
})(jQuery);
