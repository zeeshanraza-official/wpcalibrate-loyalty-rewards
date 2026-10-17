<?php
declare(strict_types=1);

namespace WPCalibrate\LoyaltyRewards\Points;

use WPCalibrate\LoyaltyRewards\Database\TransactionRepository;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Manages customer point balances with fast cached user meta and ledger reconciliation.
 */
class BalanceService
{
    public const USER_META_BALANCE = 'wpclr_points_balance';

    private TransactionRepository $repository;

    public function __construct(?TransactionRepository $repository = null)
    {
        $this->repository = $repository ?? new TransactionRepository();
    }

    /**
     * Get the customer's current available point balance.
     */
    public function getBalance(int $userId): int
    {
        if ($userId <= 0) {
            return 0;
        }

        $cached = get_user_meta($userId, self::USER_META_BALANCE, true);

        if ($cached === '' || $cached === false) {
            // Cold cache: calculate directly from the ledger and cache
            $balance = $this->repository->calculateBalanceFromLedger($userId);
            $this->updateCachedBalance($userId, $balance);
            return $balance;
        }

        return (int) $cached;
    }

    /**
     * Atomically update the customer's cached balance in user meta.
     */
    public function updateCachedBalance(int $userId, int $newBalance): void
    {
        if ($userId <= 0) {
            return;
        }

        update_user_meta($userId, self::USER_META_BALANCE, $newBalance);
    }

    /**
     * Reconcile a customer's cached balance against the ledger source of truth.
     *
     * @return array{user_id: int, previous_cached: int, actual_ledger: int, reconciled: bool}
     */
    public function reconcile(int $userId): array
    {
        $cached = (int) get_user_meta($userId, self::USER_META_BALANCE, true);
        $actual = $this->repository->calculateBalanceFromLedger($userId);

        $mismatch = ($cached !== $actual);

        if ($mismatch) {
            $this->updateCachedBalance($userId, $actual);
        }

        return [
            'user_id'         => $userId,
            'previous_cached' => $cached,
            'actual_ledger'   => $actual,
            'reconciled'      => $mismatch,
        ];
    }
}
