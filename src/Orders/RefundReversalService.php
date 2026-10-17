<?php
declare(strict_types=1);

namespace WPCalibrate\LoyaltyRewards\Orders;

use WPCalibrate\LoyaltyRewards\Points\TransactionService;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles deterministic reversal of earned points and restoration of redeemed points on order cancellation or refund.
 */
class RefundReversalService
{
    private TransactionService $transactionService;

    public function __construct(?TransactionService $transactionService = null)
    {
        $this->transactionService = $transactionService ?? new TransactionService();
    }

    /**
     * Register refund and cancellation hooks.
     */
    public function register(): void
    {
        add_action('woocommerce_order_refunded', [$this, 'handleOrderRefunded'], 20, 2);
        add_action('woocommerce_order_status_cancelled', [$this, 'handleOrderCancelled'], 20, 1);
    }

    /**
     * Handle WooCommerce order refund (both full and partial).
     *
     * @param int $orderId  Parent Order ID.
     * @param int $refundId Refund Post/Object ID.
     */
    public function handleOrderRefunded(int $orderId, int $refundId): void
    {
        $order = wc_get_order($orderId);
        $refund = wc_get_order($refundId);

        if (!$order || !$refund) {
            return;
        }

        $userId = $order->get_customer_id();
        if ($userId <= 0) {
            return;
        }

        $orderTotal = (float) $order->get_total();
        if ($orderTotal <= 0.0) {
            return;
        }

        $refundAmount = abs((float) $refund->get_amount());
        $proportion = min(1.0, max(0.0, $refundAmount / $orderTotal));

        // 1. Reversal of earned points
        $pointsEarned = (int) $order->get_meta('_wpclr_points_earned');
        $parentEarnTxId = (int) $order->get_meta('_wpclr_earn_transaction_id');

        if ($pointsEarned > 0) {
            $pointsToReverse = (int) round($pointsEarned * $proportion);
            // Cap to remaining unreversed
            $alreadyReversed = (int) $order->get_meta('_wpclr_points_earned_reversed');
            $remainingToReverse = max(0, $pointsEarned - $alreadyReversed);
            $pointsToReverse = min($pointsToReverse, $remainingToReverse);

            if ($pointsToReverse > 0) {
                $this->transactionService->reverseEarnedPoints(
                    $userId,
                    $orderId,
                    $pointsToReverse,
                    $refundId,
                    $parentEarnTxId > 0 ? $parentEarnTxId : null
                );
                $order->update_meta_data('_wpclr_points_earned_reversed', $alreadyReversed + $pointsToReverse);
            }
        }

        // 2. Restoration of redeemed points
        $pointsRedeemed = (int) $order->get_meta('_wpclr_points_redeemed');
        $parentRedeemTxId = (int) $order->get_meta('_wpclr_redeem_transaction_id');

        if ($pointsRedeemed > 0) {
            $pointsToRestore = (int) round($pointsRedeemed * $proportion);
            $alreadyRestored = (int) $order->get_meta('_wpclr_points_redeemed_restored');
            $remainingToRestore = max(0, $pointsRedeemed - $alreadyRestored);
            $pointsToRestore = min($pointsToRestore, $remainingToRestore);

            if ($pointsToRestore > 0) {
                $this->transactionService->restoreRedeemedPoints(
                    $userId,
                    $orderId,
                    $pointsToRestore,
                    $refundId,
                    $parentRedeemTxId > 0 ? $parentRedeemTxId : null
                );
                $order->update_meta_data('_wpclr_points_redeemed_restored', $alreadyRestored + $pointsToRestore);
            }
        }

        $order->save();
    }

    /**
     * Handle complete order cancellation.
     */
    public function handleOrderCancelled(int $orderId): void
    {
        $order = wc_get_order($orderId);
        if (!$order) {
            return;
        }

        $userId = $order->get_customer_id();
        if ($userId <= 0) {
            return;
        }

        // Reversal of remaining earned points
        $pointsEarned = (int) $order->get_meta('_wpclr_points_earned');
        $alreadyReversed = (int) $order->get_meta('_wpclr_points_earned_reversed');
        $remainingEarned = max(0, $pointsEarned - $alreadyReversed);
        $parentEarnTxId = (int) $order->get_meta('_wpclr_earn_transaction_id');

        if ($remainingEarned > 0) {
            $this->transactionService->reverseEarnedPoints(
                $userId,
                $orderId,
                $remainingEarned,
                null,
                $parentEarnTxId > 0 ? $parentEarnTxId : null
            );
            $order->update_meta_data('_wpclr_points_earned_reversed', $pointsEarned);
        }

        // Restoration of remaining redeemed points
        $pointsRedeemed = (int) $order->get_meta('_wpclr_points_redeemed');
        $alreadyRestored = (int) $order->get_meta('_wpclr_points_redeemed_restored');
        $remainingRedeemed = max(0, $pointsRedeemed - $alreadyRestored);
        $parentRedeemTxId = (int) $order->get_meta('_wpclr_redeem_transaction_id');

        if ($remainingRedeemed > 0) {
            $this->transactionService->restoreRedeemedPoints(
                $userId,
                $orderId,
                $remainingRedeemed,
                null,
                $parentRedeemTxId > 0 ? $parentRedeemTxId : null
            );
            $order->update_meta_data('_wpclr_points_redeemed_restored', $pointsRedeemed);
        }

        $order->save();
    }
}
