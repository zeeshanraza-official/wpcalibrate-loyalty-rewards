<?php
declare(strict_types=1);

namespace WPCalibrate\LoyaltyRewards\Orders;

use WPCalibrate\LoyaltyRewards\Customers\FirstOrderRewardService;
use WPCalibrate\LoyaltyRewards\Points\CalculationEngine;
use WPCalibrate\LoyaltyRewards\Points\TransactionService;
use WPCalibrate\LoyaltyRewards\Settings\SettingsManager;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles awarding points when WooCommerce orders transition to eligible qualifying statuses.
 */
class OrderRewardService
{
    private CalculationEngine $calculationEngine;
    private TransactionService $transactionService;
    private FirstOrderRewardService $firstOrderRewardService;

    public function __construct(
        ?CalculationEngine $calculationEngine = null,
        ?TransactionService $transactionService = null,
        ?FirstOrderRewardService $firstOrderRewardService = null
    ) {
        $this->calculationEngine = $calculationEngine ?? new CalculationEngine();
        $this->transactionService = $transactionService ?? new TransactionService();
        $this->firstOrderRewardService = $firstOrderRewardService ?? new FirstOrderRewardService($this->transactionService);
    }

    /**
     * Register order status hooks.
     */
    public function register(): void
    {
        add_action('woocommerce_order_status_changed', [$this, 'handleOrderStatusChanged'], 10, 4);
    }

    /**
     * Handle order status transition to award qualifying points idempotently.
     *
     * @param int       $orderId   Order ID.
     * @param string    $statusFrom Previous status.
     * @param string    $statusTo   New status.
     * @param \WC_Order $order      Order instance.
     */
    public function handleOrderStatusChanged(int $orderId, string $statusFrom, string $statusTo, \WC_Order $order): void
    {
        $eligibleStatuses = (array) SettingsManager::get('eligible_order_statuses', ['completed']);

        // Only process if transitioning into an eligible status
        if (!in_array($statusTo, $eligibleStatuses, true)) {
            return;
        }

        $userId = $order->get_customer_id();
        if ($userId <= 0) {
            // Guest orders do not earn loyalty points
            return;
        }

        // Check if points for this order have already been awarded
        if ($order->get_meta('_wpclr_points_earned_awarded') === 'yes') {
            return;
        }

        $calculation = $this->calculationEngine->calculateOrderPoints($order);
        $points = $calculation['points'];

        if ($points > 0) {
            $transaction = $this->transactionService->awardOrderPoints($userId, $orderId, $points, [
                'eligible_amount'     => $calculation['eligible_amount'],
                'tier_multiplier'     => $calculation['tier_multiplier'],
                'campaign_multiplier' => $calculation['campaign_multiplier'],
            ]);

            // Persist points metadata to the order using WC CRUD
            $order->update_meta_data('_wpclr_points_earned', $points);
            $order->update_meta_data('_wpclr_points_earned_awarded', 'yes');
            $order->update_meta_data('_wpclr_earn_transaction_id', $transaction['id'] ?? 0);
            $order->save();
        }

        // Check and award first order bonus if eligible
        $this->firstOrderRewardService->maybeAwardFirstOrderBonus($order);
    }
}
