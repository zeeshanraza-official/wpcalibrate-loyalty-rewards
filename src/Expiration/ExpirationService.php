<?php
declare(strict_types=1);

namespace WPCalibrate\LoyaltyRewards\Expiration;

use WPCalibrate\LoyaltyRewards\Database\Schema;
use WPCalibrate\LoyaltyRewards\Database\TransactionRepository;
use WPCalibrate\LoyaltyRewards\Points\TransactionService;
use WPCalibrate\LoyaltyRewards\Settings\SettingsManager;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles automated point expiration in bounded batches and expiry warning email dispatch.
 */
class ExpirationService
{
    private TransactionService $transactionService;
    private TransactionRepository $repository;

    public function __construct(
        ?TransactionService $transactionService = null,
        ?TransactionRepository $repository = null
    ) {
        $this->transactionService = $transactionService ?? new TransactionService();
        $this->repository = $repository ?? $this->transactionService->getRepository();
    }

    /**
     * Register Action Scheduler callbacks.
     */
    public function register(): void
    {
        add_action('wpcalibrate_loyalty_rewards_expire_points', [$this, 'processExpiredPointsBatch']);
        add_action('wpcalibrate_loyalty_rewards_send_expiry_warnings', [$this, 'processExpiryWarningsBatch']);
    }

    /**
     * Process a bounded batch of expired points transactions.
     *
     * @param int $batchSize Number of records to process per run.
     * @return int Number of transactions expired.
     */
    public function processExpiredPointsBatch(int $batchSize = 100): int
    {
        if (!SettingsManager::get('expiry_enabled', false)) {
            return 0;
        }

        global $wpdb;
        $table = Schema::getTableName();
        $now = gmdate('Y-m-d H:i:s');
        $today = gmdate('Y-m-d');

        // Find positive earn transactions that have expired and have not had a corresponding child expiry transaction
        $sql = "SELECT t.id, t.user_id, t.points, t.expires_at 
                FROM {$table} t 
                LEFT JOIN {$table} e ON e.parent_transaction_id = t.id AND e.transaction_type = 'expiry'
                WHERE t.status = 'completed' 
                AND t.points > 0 
                AND t.expires_at IS NOT NULL 
                AND t.expires_at <= %s 
                AND e.id IS NULL 
                ORDER BY t.expires_at ASC, t.id ASC 
                LIMIT %d";

        $rows = $wpdb->get_results(
            $wpdb->prepare($sql, $now, $batchSize), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            ARRAY_A
        );

        if (empty($rows)) {
            return 0;
        }

        $processedCount = 0;

        foreach ($rows as $row) {
            $userId = (int) $row['user_id'];
            $points = (int) $row['points'];
            $parentTxId = (int) $row['id'];

            // Customer must have a positive balance to expire
            $currentBalance = $this->transactionService->getBalanceService()->getBalance($userId);
            if ($currentBalance <= 0) {
                continue;
            }

            $pointsToExpire = min($points, $currentBalance);

            if ($pointsToExpire > 0) {
                try {
                    $this->transactionService->expirePoints($userId, $pointsToExpire, $today, $parentTxId);
                    $processedCount++;
                } catch (\Throwable $e) {
                    if (function_exists('wc_get_logger')) {
                        wc_get_logger()->error(
                            sprintf('Error expiring points for user #%d tx #%d: %s', $userId, $parentTxId, $e->getMessage()),
                            ['source' => 'wpcalibrate-loyalty-rewards']
                        );
                    }
                }
            }
        }

        return $processedCount;
    }

    /**
     * Send expiry warning emails to customers with points expiring soon.
     */
    public function processExpiryWarningsBatch(int $batchSize = 50): int
    {
        if (!SettingsManager::get('expiry_enabled', false) || !SettingsManager::get('email_points_expiring_enabled', true)) {
            return 0;
        }

        global $wpdb;
        $table = Schema::getTableName();
        $warningDays = (int) SettingsManager::get('expiry_warning_days', 14);
        $now = gmdate('Y-m-d H:i:s');
        $cutoff = gmdate('Y-m-d H:i:s', strtotime("+{$warningDays} days"));

        // Query distinct users with points expiring between now and cutoff date
        $sql = "SELECT DISTINCT t.user_id 
                FROM {$table} t 
                LEFT JOIN {$table} e ON e.parent_transaction_id = t.id AND e.transaction_type = 'expiry'
                WHERE t.status = 'completed' 
                AND t.points > 0 
                AND t.expires_at IS NOT NULL 
                AND t.expires_at > %s 
                AND t.expires_at <= %s 
                AND e.id IS NULL 
                LIMIT %d";

        $userIds = $wpdb->get_col(
            $wpdb->prepare($sql, $now, $cutoff, $batchSize) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        );

        if (empty($userIds)) {
            return 0;
        }

        $sentCount = 0;
        $today = gmdate('Y-m-d');

        foreach ($userIds as $userId) {
            $userId = (int) $userId;

            // Prevent spamming: send at most once per 7 days per customer
            $lastSent = get_user_meta($userId, 'wpclr_last_expiry_warning_date', true);
            if ($lastSent && (strtotime($today) - strtotime((string) $lastSent)) < (7 * DAY_IN_SECONDS)) {
                continue;
            }

            $expiringPoints = $this->repository->getExpiringPoints($userId, $cutoff);
            if ($expiringPoints <= 0) {
                continue;
            }

            // Get the earliest expiration date for this customer
            $earliestExpiry = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT MIN(expires_at) FROM {$table} WHERE user_id = %d AND status = 'completed' AND points > 0 AND expires_at > %s AND expires_at <= %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                    $userId,
                    $now,
                    $cutoff
                )
            );

            // Fire action for WooCommerce transactional email dispatch
            do_action('wpcalibrate_loyalty_rewards_send_expiry_warning', $userId, $expiringPoints, (string) $earliestExpiry);

            update_user_meta($userId, 'wpclr_last_expiry_warning_date', $today);
            $sentCount++;
        }

        return $sentCount;
    }
}
