<?php
declare(strict_types=1);

namespace WPCalibrate\LoyaltyRewards\Customers;

use WPCalibrate\LoyaltyRewards\Points\TransactionService;
use WPCalibrate\LoyaltyRewards\Settings\SettingsManager;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles awarding the first-order bonus reward.
 */
class FirstOrderRewardService
{
    private TransactionService $transactionService;

    public function __construct(?TransactionService $transactionService = null)
    {
        $this->transactionService = $transactionService ?? new TransactionService();
    }

    /**
     * Check order and customer history and award first-order bonus if qualifying.
     */
    public function maybeAwardFirstOrderBonus(\WC_Order $order): void
    {
        if (!SettingsManager::get('first_order_bonus_enabled', false)) {
            return;
        }

        $userId = $order->get_customer_id();
        if ($userId <= 0) {
            return;
        }

        $bonusPoints = (int) SettingsManager::get('first_order_bonus_points', 100);
        if ($bonusPoints <= 0) {
            return;
        }

        // Check if user already received a first-order bonus in the ledger
        $existing = $this->transactionService->getRepository()->findByEventKey(sprintf('earn_first_order_%d', $userId));
        if ($existing !== null) {
            return;
        }

        // Query historical customer orders to confirm this is genuinely their first order
        $eligibleStatuses = (array) SettingsManager::get('eligible_order_statuses', ['completed']);
        $previousOrders = wc_get_orders([
            'customer_id' => $userId,
            'status'      => $eligibleStatuses,
            'exclude'     => [$order->get_id()],
            'limit'       => 1,
            'return'      => 'ids',
        ]);

        if (!empty($previousOrders)) {
            // Customer has prior qualifying orders; not eligible for first-order bonus
            return;
        }

        // Award first order bonus
        $this->transactionService->awardFirstOrderBonus($userId, $order->get_id(), $bonusPoints);
    }
}
