<?php
declare(strict_types=1);

namespace WPCalibrate\LoyaltyRewards\Redemption;

use WPCalibrate\LoyaltyRewards\Points\TransactionService;
use WPCalibrate\LoyaltyRewards\Settings\SettingsManager;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles Classic WooCommerce Cart and Checkout points redemption UI, session state, and order commitment.
 */
class ClassicCartRedemption
{
    public const SESSION_KEY = 'wpclr_applied_points';

    private RedemptionService $redemptionService;
    private TransactionService $transactionService;

    public function __construct(
        ?RedemptionService $redemptionService = null,
        ?TransactionService $transactionService = null
    ) {
        $this->redemptionService = $redemptionService ?? new RedemptionService();
        $this->transactionService = $transactionService ?? new TransactionService();
    }

    /**
     * Register hooks.
     */
    public function register(): void
    {
        // Enqueue frontend scripts/styles on cart/checkout
        add_action('wp_enqueue_scripts', [$this, 'enqueueAssets']);

        // Render redemption form on Classic Cart & Checkout
        add_action('woocommerce_before_cart_table', [$this, 'renderRedemptionForm'], 15);
        add_action('woocommerce_before_checkout_form', [$this, 'renderRedemptionForm'], 10);

        // AJAX handlers
        add_action('wp_ajax_wpclr_apply_points', [$this, 'handleApplyPointsAjax']);
        add_action('wp_ajax_wpclr_remove_points', [$this, 'handleRemovePointsAjax']);

        // Cart totals calculation: apply direct negative fee
        add_action('woocommerce_cart_calculate_fees', [$this, 'applyDiscountFee'], 20, 1);

        // Order creation & commitment
        add_action('woocommerce_checkout_order_created', [$this, 'handleOrderCreated'], 20, 1);
        add_action('woocommerce_checkout_order_processed', [$this, 'handleOrderProcessed'], 20, 3);
    }

    /**
     * Enqueue frontend assets for redemption.
     */
    public function enqueueAssets(): void
    {
        if (!is_cart() && !is_checkout()) {
            return;
        }

        wp_enqueue_style(
            'wpclr-frontend',
            WPCLR_PLUGIN_URL . 'assets/css/frontend.css',
            [],
            WPCLR_VERSION
        );

        wp_enqueue_script(
            'wpclr-redemption',
            WPCLR_PLUGIN_URL . 'assets/js/redemption.js',
            ['jquery'],
            WPCLR_VERSION,
            true
        );

        wp_localize_script('wpclr-redemption', 'wpclrRedemption', [
            'ajaxUrl'   => admin_url('admin-ajax.php'),
            'nonce'     => wp_create_nonce('wpclr_redemption_nonce'),
            'isUser'    => is_user_logged_in(),
            'i18n'      => [
                'applying' => __('Applying points...', 'wpcalibrate-loyalty-rewards'),
                'removing' => __('Removing points...', 'wpcalibrate-loyalty-rewards'),
                'error'    => __('An error occurred while updating points.', 'wpcalibrate-loyalty-rewards'),
            ],
        ]);
    }

    /**
     * Render the customer rewards redemption box on Cart & Checkout pages.
     */
    public function renderRedemptionForm(): void
    {
        if (!is_user_logged_in() || !WC()->cart) {
            return;
        }

        $userId = get_current_user_id();
        $limits = $this->redemptionService->getCartRedemptionLimits($userId, WC()->cart);
        $appliedPoints = (int) (WC()->session ? WC()->session->get(self::SESSION_KEY, 0) : 0);

        if (!$limits['eligible'] && $appliedPoints <= 0) {
            return;
        }

        $pointLabelSingular = SettingsManager::get('point_label_singular', 'Point');
        $pointLabelPlural = SettingsManager::get('point_label_plural', 'Points');
        ?>
        <div id="wpclr-redemption-box" class="wpclr-redemption-card" role="region" aria-label="<?php esc_attr_e('Loyalty Rewards Redemption', 'wpcalibrate-loyalty-rewards'); ?>">
            <div class="wpclr-redemption-header">
                <span class="wpclr-redemption-icon" aria-hidden="true">★</span>
                <div>
                    <h3 class="wpclr-redemption-title"><?php esc_html_e('Redeem Loyalty Rewards', 'wpcalibrate-loyalty-rewards'); ?></h3>
                    <p class="wpclr-redemption-balance">
                        <?php
                        printf(
                            /* translators: 1: Available points, 2: Label plural, 3: Monetary value */
                            esc_html__('You have %1$s %2$s available (worth %3$s).', 'wpcalibrate-loyalty-rewards'),
                            '<strong>' . number_format_i18n($limits['customer_balance']) . '</strong>',
                            esc_html($pointLabelPlural),
                            '<strong>' . wc_price($limits['customer_balance'] / max(1, $limits['point_ratio'])) . '</strong>'
                        );
                        ?>
                    </p>
                </div>
            </div>

            <div class="wpclr-redemption-body">
                <?php if ($appliedPoints > 0) : ?>
                    <div class="wpclr-applied-notice" role="status">
                        <p>
                            <?php
                            printf(
                                /* translators: 1: Applied points, 2: Monetary discount */
                                esc_html__('Currently applied: %1$s points for a %2$s discount.', 'wpcalibrate-loyalty-rewards'),
                                '<strong>' . number_format_i18n($appliedPoints) . '</strong>',
                                '<strong>' . wc_price($appliedPoints / max(1, $limits['point_ratio'])) . '</strong>'
                            );
                            ?>
                        </p>
                        <button type="button" class="button wpclr-btn-remove" id="wpclr-btn-remove" aria-label="<?php esc_attr_e('Remove applied loyalty points', 'wpcalibrate-loyalty-rewards'); ?>">
                            <?php esc_html_e('Remove Points', 'wpcalibrate-loyalty-rewards'); ?>
                        </button>
                    </div>
                <?php else : ?>
                    <form id="wpclr-redemption-form" class="wpclr-form-inline">
                        <label for="wpclr-points-input" class="screen-reader-text">
                            <?php esc_html_e('Points to redeem', 'wpcalibrate-loyalty-rewards'); ?>
                        </label>
                        <input
                            type="number"
                            id="wpclr-points-input"
                            class="input-text wpclr-input"
                            min="<?php echo esc_attr((string) $limits['min_points']); ?>"
                            max="<?php echo esc_attr((string) $limits['max_points']); ?>"
                            step="1"
                            placeholder="<?php echo esc_attr(sprintf(__('Min %d, Max %d', 'wpcalibrate-loyalty-rewards'), $limits['min_points'], $limits['max_points'])); ?>"
                            value="<?php echo esc_attr((string) $limits['max_points']); ?>"
                        />
                        <button type="button" class="button button-primary wpclr-btn-apply" id="wpclr-btn-apply">
                            <?php esc_html_e('Apply Discount', 'wpcalibrate-loyalty-rewards'); ?>
                        </button>
                    </form>
                    <div id="wpclr-msg-area" class="wpclr-message" aria-live="polite"></div>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /**
     * Handle AJAX apply points.
     */
    public function handleApplyPointsAjax(): void
    {
        check_ajax_referer('wpclr_redemption_nonce', 'nonce');

        if (!is_user_logged_in() || !WC()->cart) {
            wp_send_json_error(['message' => __('You must be logged in to apply reward points.', 'wpcalibrate-loyalty-rewards')]);
        }

        $userId = get_current_user_id();
        $points = absint($_POST['points'] ?? 0);

        $validation = $this->redemptionService->validateRedemption($userId, $points, WC()->cart);

        if (!$validation['valid']) {
            wp_send_json_error(['message' => $validation['message']]);
        }

        // Store validated points in session
        WC()->session->set(self::SESSION_KEY, $validation['points']);

        WC()->cart->calculate_totals();

        wp_send_json_success([
            'message'  => $validation['message'],
            'points'   => $validation['points'],
            'discount' => $validation['discount'],
        ]);
    }

    /**
     * Handle AJAX remove points.
     */
    public function handleRemovePointsAjax(): void
    {
        check_ajax_referer('wpclr_redemption_nonce', 'nonce');

        if (WC()->session) {
            WC()->session->__unset(self::SESSION_KEY);
        }

        if (WC()->cart) {
            WC()->cart->calculate_totals();
        }

        wp_send_json_success(['message' => __('Reward points removed from cart.', 'wpcalibrate-loyalty-rewards')]);
    }

    /**
     * Apply discount as a fee on WooCommerce cart totals calculation.
     *
     * @param \WC_Cart $cart
     */
    public function applyDiscountFee(\WC_Cart $cart): void
    {
        if (is_admin() && !defined('DOING_AJAX')) {
            return;
        }

        if (!WC()->session) {
            return;
        }

        $appliedPoints = (int) WC()->session->get(self::SESSION_KEY, 0);
        if ($appliedPoints <= 0) {
            return;
        }

        $userId = get_current_user_id();
        if ($userId <= 0) {
            WC()->session->__unset(self::SESSION_KEY);
            return;
        }

        $validation = $this->redemptionService->validateRedemption($userId, $appliedPoints, $cart);
        if (!$validation['valid']) {
            // Cart state changed and no longer meets limits
            WC()->session->__unset(self::SESSION_KEY);
            return;
        }

        // If validated points changed due to subtotal reductions, update session
        if ($validation['points'] !== $appliedPoints) {
            $appliedPoints = $validation['points'];
            WC()->session->set(self::SESSION_KEY, $appliedPoints);
        }

        $discount = (float) $validation['discount'];
        if ($discount > 0.0) {
            // Negative fee adds a discount line item cleanly
            $cart->add_fee(
                __('Loyalty Reward Discount', 'wpcalibrate-loyalty-rewards'),
                -$discount,
                false // not taxable
            );
        }
    }

    /**
     * Persist redeemed points metadata to order upon creation.
     */
    public function handleOrderCreated(\WC_Order $order): void
    {
        if (!WC()->session) {
            return;
        }

        $appliedPoints = (int) WC()->session->get(self::SESSION_KEY, 0);
        if ($appliedPoints <= 0) {
            return;
        }

        $userId = $order->get_customer_id();
        if ($userId <= 0) {
            return;
        }

        $ratio = (int) SettingsManager::get('redemption_ratio', 100);
        $discount = round($appliedPoints / $ratio, 2);

        $order->update_meta_data('_wpclr_points_redeemed', $appliedPoints);
        $order->update_meta_data('_wpclr_rewards_discount', $discount);
        $order->save();
    }

    /**
     * Commit points deduction when order is processed/placed.
     */
    public function handleOrderProcessed(int $orderId, array $postedData, \WC_Order $order): void
    {
        $pointsRedeemed = (int) $order->get_meta('_wpclr_points_redeemed');
        if ($pointsRedeemed <= 0) {
            return;
        }

        // Check if deduction was already committed
        if ($order->get_meta('_wpclr_points_redeemed_committed') === 'yes') {
            return;
        }

        $userId = $order->get_customer_id();
        if ($userId <= 0) {
            return;
        }

        $discount = (float) $order->get_meta('_wpclr_rewards_discount');

        try {
            $transaction = $this->transactionService->redeemOrderPoints($userId, $orderId, $pointsRedeemed, $discount);

            $order->update_meta_data('_wpclr_points_redeemed_committed', 'yes');
            $order->update_meta_data('_wpclr_redeem_transaction_id', $transaction['id'] ?? 0);
            $order->save();

            // Clear session applied points
            if (WC()->session) {
                WC()->session->__unset(self::SESSION_KEY);
            }
        } catch (\Throwable $e) {
            if (function_exists('wc_get_logger')) {
                wc_get_logger()->error(
                    sprintf('Failed to deduct points on order #%d: %s', $orderId, $e->getMessage()),
                    ['source' => 'wpcalibrate-loyalty-rewards']
                );
            }
        }
    }
}
