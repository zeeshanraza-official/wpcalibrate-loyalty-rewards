<?php
declare(strict_types=1);

namespace WPCalibrate\LoyaltyRewards\Orders;

use WPCalibrate\LoyaltyRewards\Points\BalanceService;
use WPCalibrate\LoyaltyRewards\Settings\SettingsManager;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles displaying loyalty and reward points metadata inside WooCommerce order administration screens.
 */
class OrderAdminIntegration
{
    private BalanceService $balanceService;

    public function __construct(?BalanceService $balanceService = null)
    {
        $this->balanceService = $balanceService ?? new BalanceService();
    }

    /**
     * Register order admin hooks.
     */
    public function register(): void
    {
        // Display in WooCommerce Order Admin sidebar metabox or order data panel
        add_action('add_meta_boxes', [$this, 'addOrderMetabox'], 25);
        add_action('woocommerce_admin_order_totals_after_discount', [$this, 'renderOrderTotalsRewardRow']);
    }

    /**
     * Register custom metabox on WooCommerce orders (compatible with traditional and HPOS).
     */
    public function addOrderMetabox(): void
    {
        $screen = class_exists('\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController') &&
            wc_get_container()->get(\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class)->custom_orders_table_usage_is_enabled()
            ? wc_get_page_screen_id('shop_order')
            : 'shop_order';

        add_meta_box(
            'wpclr_order_loyalty_data',
            __('Loyalty & Rewards', 'wpcalibrate-loyalty-rewards'),
            [$this, 'renderOrderMetabox'],
            $screen,
            'side',
            'default'
        );
    }

    /**
     * Render order metabox content.
     *
     * @param \WP_Post|\WC_Order $postOrOrder
     */
    public function renderOrderMetabox(mixed $postOrOrder): void
    {
        $order = $postOrOrder instanceof \WC_Order ? $postOrOrder : wc_get_order($postOrOrder->ID);
        if (!$order) {
            return;
        }

        $userId = $order->get_customer_id();
        $pointsEarned = (int) $order->get_meta('_wpclr_points_earned');
        $pointsRedeemed = (int) $order->get_meta('_wpclr_points_redeemed');
        $discount = (float) $order->get_meta('_wpclr_rewards_discount');
        $currentBalance = $userId > 0 ? $this->balanceService->getBalance($userId) : 0;

        $pointLabelSingular = SettingsManager::get('point_label_singular', 'Point');
        $pointLabelPlural = SettingsManager::get('point_label_plural', 'Points');
        ?>
        <div class="wpclr-order-admin-panel" style="padding: 6px 0;">
            <p style="margin: 0 0 8px;">
                <strong><?php esc_html_e('Customer Current Balance:', 'wpcalibrate-loyalty-rewards'); ?></strong><br>
                <?php if ($userId > 0) : ?>
                    <span style="font-size: 1.1em; color: #2271b1; font-weight: 600;">
                        <?php echo esc_html(number_format_i18n($currentBalance) . ' ' . $pointLabelPlural); ?>
                    </span>
                <?php else : ?>
                    <em style="color: #666;"><?php esc_html_e('Guest Customer (No Balance)', 'wpcalibrate-loyalty-rewards'); ?></em>
                <?php endif; ?>
            </p>

            <hr style="border: 0; border-top: 1px solid #eee; margin: 8px 0;">

            <p style="margin: 0 0 6px;">
                <strong><?php esc_html_e('Points Earned:', 'wpcalibrate-loyalty-rewards'); ?></strong>
                <span style="float: right; font-weight: 600; color: #007017;">
                    <?php echo $pointsEarned > 0 ? '+' . esc_html(number_format_i18n($pointsEarned)) : '0'; ?>
                </span>
            </p>

            <p style="margin: 0 0 6px;">
                <strong><?php esc_html_e('Points Redeemed:', 'wpcalibrate-loyalty-rewards'); ?></strong>
                <span style="float: right; font-weight: 600; color: #b32d2e;">
                    <?php echo $pointsRedeemed > 0 ? '-' . esc_html(number_format_i18n($pointsRedeemed)) : '0'; ?>
                </span>
            </p>

            <?php if ($discount > 0.0) : ?>
                <p style="margin: 0 0 6px;">
                    <strong><?php esc_html_e('Reward Discount:', 'wpcalibrate-loyalty-rewards'); ?></strong>
                    <span style="float: right; font-weight: 600;">
                        <?php echo wc_price($discount); ?>
                    </span>
                </p>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Render reward discount row in order totals table in order admin.
     *
     * @param int $orderId
     */
    public function renderOrderTotalsRewardRow(int $orderId): void
    {
        $order = wc_get_order($orderId);
        if (!$order) {
            return;
        }

        $pointsRedeemed = (int) $order->get_meta('_wpclr_points_redeemed');
        $discount = (float) $order->get_meta('_wpclr_rewards_discount');

        if ($pointsRedeemed <= 0 && $discount <= 0.0) {
            return;
        }
        ?>
        <tr>
            <td class="label">
                <?php
                printf(
                    /* translators: %d: Redeemed points */
                    esc_html__('Loyalty Rewards (%d pts):', 'wpcalibrate-loyalty-rewards'),
                    $pointsRedeemed
                );
                ?>
            </td>
            <td width="1%"></td>
            <td class="total">
                -<?php echo wc_price($discount); ?>
            </td>
        </tr>
        <?php
    }
}
