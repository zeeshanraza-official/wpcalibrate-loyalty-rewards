<?php
declare(strict_types=1);

namespace WPCalibrate\LoyaltyRewards\Points;

use WPCalibrate\LoyaltyRewards\Database\TransactionRepository;
use WPCalibrate\LoyaltyRewards\Settings\SettingsManager;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Authoritative transaction service for all loyalty balance mutations and idempotency enforcement.
 */
class TransactionService
{
    private TransactionRepository $repository;
    private BalanceService $balanceService;

    public function __construct(
        ?TransactionRepository $repository = null,
        ?BalanceService $balanceService = null
    ) {
        $this->repository = $repository ?? new TransactionRepository();
        $this->balanceService = $balanceService ?? new BalanceService($this->repository);
    }

    /**
     * Get repository instance.
     */
    public function getRepository(): TransactionRepository
    {
        return $this->repository;
    }

    /**
     * Get balance service instance.
     */
    public function getBalanceService(): BalanceService
    {
        return $this->balanceService;
    }

    /**
     * Core method to process and record any points transaction with idempotency and balance synchronization.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed> The existing or newly created transaction record.
     * @throws \InvalidArgumentException On invalid payload or unauthorized negative balance.
     */
    public function createTransaction(array $data): array
    {
        $userId = absint($data['user_id'] ?? 0);
        if ($userId <= 0) {
            throw new \InvalidArgumentException(__('A valid user ID is required for loyalty transactions.', 'wpcalibrate-loyalty-rewards'));
        }

        $points = (int) ($data['points'] ?? 0);
        if ($points === 0) {
            throw new \InvalidArgumentException(__('Transaction points delta cannot be zero.', 'wpcalibrate-loyalty-rewards'));
        }

        // Idempotency check: If an event_key is provided and already exists, return the existing record.
        $eventKey = !empty($data['event_key']) ? sanitize_text_field((string) $data['event_key']) : null;
        if ($eventKey !== null) {
            $existing = $this->repository->findByEventKey($eventKey);
            if ($existing !== null) {
                return $existing;
            }
        }

        // Check balance constraint for customer redemption (prevent negative balance)
        $allowNegative = !empty($data['allow_negative']);
        $currentBalance = $this->balanceService->getBalance($userId);
        $newBalance = $currentBalance + $points;

        if ($newBalance < 0 && !$allowNegative) {
            throw new \InvalidArgumentException(
                sprintf(
                    /* translators: 1: Current balance, 2: Requested points to deduct */
                    __('Insufficient loyalty points balance. Current balance: %1$d, requested deduction: %2$d.', 'wpcalibrate-loyalty-rewards'),
                    $currentBalance,
                    abs($points)
                )
            );
        }

        /**
         * Fires before a transaction is inserted into the ledger.
         *
         * @param array $data Transaction payload.
         */
        do_action('wpcalibrate_loyalty_rewards_before_create_transaction', $data);

        // Insert into database ledger
        $insertId = $this->repository->insert($data);
        if ($insertId <= 0) {
            // Check again if duplicate occurred concurrently
            if ($eventKey !== null) {
                $existing = $this->repository->findByEventKey($eventKey);
                if ($existing !== null) {
                    return $existing;
                }
            }
            throw new \RuntimeException(__('Failed to write transaction to the loyalty ledger.', 'wpcalibrate-loyalty-rewards'));
        }

        // Update cached balance atomically
        $this->balanceService->updateCachedBalance($userId, $newBalance);

        $savedTransaction = $this->repository->findById($insertId);
        if (!$savedTransaction) {
            $savedTransaction = array_merge($data, ['id' => $insertId]);
        }

        /**
         * Fires after a transaction is successfully written and balance updated.
         *
         * @param int   $insertId         Transaction ID.
         * @param array $savedTransaction Full transaction record.
         */
        do_action('wpcalibrate_loyalty_rewards_after_create_transaction', $insertId, $savedTransaction);

        /**
         * Fires whenever a customer's loyalty balance changes.
         *
         * @param int $userId      User ID.
         * @param int $newBalance  Updated balance.
         * @param int $points      Delta points.
         */
        do_action('wpcalibrate_loyalty_rewards_customer_balance_changed', $userId, $newBalance, $points);

        return $savedTransaction;
    }

    /**
     * Award purchase points from an eligible order.
     */
    public function awardOrderPoints(int $userId, int $orderId, int $points, array $meta = []): array
    {
        $eventKey = sprintf('earn_order_%d_%d', $orderId, $userId);

        // Determine expiration timestamp if point expiry is enabled
        $expiresAt = null;
        if (SettingsManager::get('expiry_enabled', false)) {
            $days = (int) SettingsManager::get('expiry_period_days', 365);
            $expiresAt = gmdate('Y-m-d H:i:s', strtotime("+{$days} days"));
        }

        return $this->createTransaction([
            'user_id'          => $userId,
            'points'           => $points,
            'transaction_type' => 'earn_order',
            'status'           => 'completed',
            'source_type'      => 'order',
            'source_id'        => $orderId,
            'order_id'         => $orderId,
            'event_key'        => $eventKey,
            'description'      => sprintf(__('Points earned from Order #%d', 'wpcalibrate-loyalty-rewards'), $orderId),
            'metadata'         => $meta,
            'expires_at'       => $expiresAt,
        ]);
    }

    /**
     * Redeem points as a cart/order discount.
     */
    public function redeemOrderPoints(int $userId, int $orderId, int $points, float $monetaryDiscount, array $meta = []): array
    {
        $eventKey = sprintf('redeem_order_%d_%d', $orderId, $userId);

        $meta['monetary_discount'] = $monetaryDiscount;
        $meta['currency'] = function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : 'USD';

        return $this->createTransaction([
            'user_id'          => $userId,
            'points'           => -abs($points),
            'transaction_type' => 'redeem',
            'status'           => 'completed',
            'source_type'      => 'order',
            'source_id'        => $orderId,
            'order_id'         => $orderId,
            'event_key'        => $eventKey,
            'description'      => sprintf(__('Points redeemed for discount on Order #%d', 'wpcalibrate-loyalty-rewards'), $orderId),
            'metadata'         => $meta,
            'allow_negative'   => false,
        ]);
    }

    /**
     * Reverse earned points from an order cancellation or refund.
     */
    public function reverseEarnedPoints(int $userId, int $orderId, int $pointsToReverse, ?int $refundId = null, ?int $parentTxId = null): array
    {
        $eventKey = $refundId
            ? sprintf('refund_reverse_order_%d_ref_%d', $orderId, $refundId)
            : sprintf('cancel_reverse_order_%d', $orderId);

        return $this->createTransaction([
            'user_id'               => $userId,
            'points'                => -abs($pointsToReverse),
            'transaction_type'      => $refundId ? 'refund_reversal' : 'cancellation_reversal',
            'status'                => 'completed',
            'source_type'           => $refundId ? 'refund' : 'order',
            'source_id'             => $refundId ?? $orderId,
            'order_id'              => $orderId,
            'parent_transaction_id' => $parentTxId,
            'event_key'             => $eventKey,
            'description'           => $refundId
                ? sprintf(__('Reversal of earned points from Refund #%d on Order #%d', 'wpcalibrate-loyalty-rewards'), $refundId, $orderId)
                : sprintf(__('Reversal of earned points from cancelled Order #%d', 'wpcalibrate-loyalty-rewards'), $orderId),
            'allow_negative'        => true, // Accounting integrity: allow debt if customer spent points before refund
        ]);
    }

    /**
     * Restore redeemed points back to customer on order refund or cancellation.
     */
    public function restoreRedeemedPoints(int $userId, int $orderId, int $pointsToRestore, ?int $refundId = null, ?int $parentTxId = null): array
    {
        $eventKey = $refundId
            ? sprintf('refund_restore_order_%d_ref_%d', $orderId, $refundId)
            : sprintf('cancel_restore_order_%d', $orderId);

        return $this->createTransaction([
            'user_id'               => $userId,
            'points'                => abs($pointsToRestore),
            'transaction_type'      => 'adjustment',
            'status'                => 'completed',
            'source_type'           => $refundId ? 'refund' : 'order',
            'source_id'             => $refundId ?? $orderId,
            'order_id'              => $orderId,
            'parent_transaction_id' => $parentTxId,
            'event_key'             => $eventKey,
            'description'           => $refundId
                ? sprintf(__('Restoration of redeemed points from Refund #%d on Order #%d', 'wpcalibrate-loyalty-rewards'), $refundId, $orderId)
                : sprintf(__('Restoration of redeemed points from cancelled Order #%d', 'wpcalibrate-loyalty-rewards'), $orderId),
            'allow_negative'        => false,
        ]);
    }

    /**
     * Award registration bonus to a newly registered user.
     */
    public function awardRegistrationBonus(int $userId, int $points): ?array
    {
        if ($points <= 0) {
            return null;
        }

        $eventKey = sprintf('earn_reg_%d', $userId);

        return $this->createTransaction([
            'user_id'          => $userId,
            'points'           => $points,
            'transaction_type' => 'earn_registration',
            'status'           => 'completed',
            'source_type'      => 'registration',
            'source_id'        => $userId,
            'event_key'        => $eventKey,
            'description'      => __('Welcome registration bonus points', 'wpcalibrate-loyalty-rewards'),
        ]);
    }

    /**
     * Award first-order bonus.
     */
    public function awardFirstOrderBonus(int $userId, int $orderId, int $points): ?array
    {
        if ($points <= 0) {
            return null;
        }

        $eventKey = sprintf('earn_first_order_%d', $userId);

        return $this->createTransaction([
            'user_id'          => $userId,
            'points'           => $points,
            'transaction_type' => 'earn_first_order',
            'status'           => 'completed',
            'source_type'      => 'order',
            'source_id'        => $orderId,
            'order_id'         => $orderId,
            'event_key'        => $eventKey,
            'description'      => sprintf(__('First order bonus for Order #%d', 'wpcalibrate-loyalty-rewards'), $orderId),
        ]);
    }

    /**
     * Award product review bonus.
     */
    public function awardReviewBonus(int $userId, int $reviewId, int $productId, int $points): ?array
    {
        if ($points <= 0) {
            return null;
        }

        $eventKey = sprintf('earn_review_%d', $reviewId);

        return $this->createTransaction([
            'user_id'          => $userId,
            'points'           => $points,
            'transaction_type' => 'earn_review',
            'status'           => 'completed',
            'source_type'      => 'review',
            'source_id'        => $reviewId,
            'product_id'       => $productId,
            'event_key'        => $eventKey,
            'description'      => sprintf(__('Bonus for approved product review #%d', 'wpcalibrate-loyalty-rewards'), $reviewId),
        ]);
    }

    /**
     * Perform an administrative manual credit or debit adjustment with audit trail.
     */
    public function manualAdjustment(int $userId, int $points, string $reason, int $adminUserId, ?int $orderId = null): array
    {
        $type = $points > 0 ? 'admin_credit' : 'admin_debit';
        $timestamp = microtime(true);
        $eventKey = sprintf('admin_adj_%d_%s_%f', $userId, $type, $timestamp);

        $meta = [
            'admin_user_id' => $adminUserId,
            'reason'        => $reason,
        ];

        return $this->createTransaction([
            'user_id'          => $userId,
            'points'           => $points,
            'transaction_type' => $type,
            'status'           => 'completed',
            'source_type'      => 'manual',
            'source_id'        => $adminUserId,
            'order_id'         => $orderId,
            'event_key'        => $eventKey,
            'description'      => sprintf(__('Admin adjustment by user #%d: %s', 'wpcalibrate-loyalty-rewards'), $adminUserId, $reason),
            'metadata'         => $meta,
            'allow_negative'   => true, // Admin can make explicit negative adjustments if authorized
        ]);
    }

    /**
     * Record expired points transaction.
     */
    public function expirePoints(int $userId, int $pointsToExpire, string $batchDate, ?int $parentTxId = null): array
    {
        $eventKey = sprintf('expiry_%d_%s_%s', $userId, $batchDate, $parentTxId ? "tx_{$parentTxId}" : 'aggregate');

        return $this->createTransaction([
            'user_id'               => $userId,
            'points'                => -abs($pointsToExpire),
            'transaction_type'      => 'expiry',
            'status'                => 'completed',
            'source_type'           => 'expiry',
            'source_id'             => null,
            'parent_transaction_id' => $parentTxId,
            'event_key'             => $eventKey,
            'description'           => sprintf(__('Points expired on %s', 'wpcalibrate-loyalty-rewards'), $batchDate),
            'allow_negative'        => true,
        ]);
    }
}
