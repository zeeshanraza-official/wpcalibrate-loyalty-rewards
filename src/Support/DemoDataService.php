<?php
declare(strict_types=1);

namespace WPCalibrate\LoyaltyRewards\Support;

use WPCalibrate\LoyaltyRewards\Campaigns\CampaignService;
use WPCalibrate\LoyaltyRewards\Database\Schema;
use WPCalibrate\LoyaltyRewards\Database\TransactionRepository;
use WPCalibrate\LoyaltyRewards\Points\BalanceService;
use WPCalibrate\LoyaltyRewards\Tiers\TierService;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Generates and clears demo loyalty transactions, VIP tiers, and multiplier campaigns
 * for demonstration, onboarding, and storefront testing.
 */
class DemoDataService
{
    private TransactionRepository $repository;
    private BalanceService $balanceService;
    private TierService $tierService;
    private CampaignService $campaignService;

    public function __construct(
        ?TransactionRepository $repository = null,
        ?BalanceService $balanceService = null,
        ?TierService $tierService = null,
        ?CampaignService $campaignService = null
    ) {
        $this->repository = $repository ?? new TransactionRepository();
        $this->balanceService = $balanceService ?? new BalanceService($this->repository);
        $this->tierService = $tierService ?? new TierService($this->repository);
        $this->campaignService = $campaignService ?? new CampaignService();
    }

    /**
     * Generate full showcase demo data for a given customer or current user.
     *
     * @param int $userId Target user ID.
     * @return array<string, mixed> Result summary.
     */
    public function generateDemoData(int $userId): array
    {
        if ($userId <= 0) {
            return [
                'success' => false,
                'message' => __('Invalid user ID provided for demo data generation.', 'wpcalibrate-loyalty-rewards'),
            ];
        }

        // 1. Clear any prior demo transactions for this user first
        $this->clearDemoData($userId);

        $now = time();

        // 2. Insert realistic staggered demo transactions
        $demoRecords = [
            [
                'user_id'          => $userId,
                'points'           => 50,
                'transaction_type' => 'earn_registration',
                'status'           => 'completed',
                'source_type'      => 'registration',
                'source_id'        => $userId,
                'event_key'        => sprintf('demo_reg_%d', $userId),
                'description'      => __('Welcome account registration bonus points', 'wpcalibrate-loyalty-rewards'),
                'created_at'       => gmdate('Y-m-d H:i:s', $now - (10 * 86400)),
            ],
            [
                'user_id'          => $userId,
                'points'           => 150,
                'transaction_type' => 'earn_order',
                'status'           => 'completed',
                'source_type'      => 'order',
                'source_id'        => 1042,
                'order_id'         => 1042,
                'event_key'        => sprintf('demo_order_1042_%d', $userId),
                'description'      => __('Points earned from qualifying Order #1042', 'wpcalibrate-loyalty-rewards'),
                'created_at'       => gmdate('Y-m-d H:i:s', $now - (7 * 86400)),
            ],
            [
                'user_id'          => $userId,
                'points'           => 25,
                'transaction_type' => 'earn_review',
                'status'           => 'completed',
                'source_type'      => 'review',
                'source_id'        => 88,
                'product_id'       => 15,
                'event_key'        => sprintf('demo_review_88_%d', $userId),
                'description'      => __('Bonus points for approved verified product review #88', 'wpcalibrate-loyalty-rewards'),
                'created_at'       => gmdate('Y-m-d H:i:s', $now - (5 * 86400)),
            ],
            [
                'user_id'          => $userId,
                'points'           => 100,
                'transaction_type' => 'earn_order',
                'status'           => 'completed',
                'source_type'      => 'order',
                'source_id'        => 1089,
                'order_id'         => 1089,
                'event_key'        => sprintf('demo_order_1089_%d', $userId),
                'description'      => __('Weekend Double Points promotional multiplier bonus on Order #1089', 'wpcalibrate-loyalty-rewards'),
                'created_at'       => gmdate('Y-m-d H:i:s', $now - (3 * 86400)),
            ],
            [
                'user_id'          => $userId,
                'points'           => -75,
                'transaction_type' => 'redeem',
                'status'           => 'completed',
                'source_type'      => 'order',
                'source_id'        => 1105,
                'order_id'         => 1105,
                'event_key'        => sprintf('demo_redeem_1105_%d', $userId),
                'description'      => __('Points redeemed for instant store discount on Order #1105', 'wpcalibrate-loyalty-rewards'),
                'metadata'         => ['monetary_discount' => 0.75, 'currency' => 'USD', 'is_demo' => true],
                'created_at'       => gmdate('Y-m-d H:i:s', $now - (1 * 86400)),
            ],
        ];

        $insertedCount = 0;
        foreach ($demoRecords as $record) {
            $insertedId = $this->repository->insert($record);
            if ($insertedId > 0) {
                $insertedCount++;
            }
        }

        // 3. Reconcile balance cache
        $reconcileResult = $this->balanceService->reconcile($userId);
        $finalBalance = (int) ($reconcileResult['actual_ledger'] ?? $this->balanceService->getBalance($userId));

        // 4. Ensure demo VIP Tiers are populated and active if not already customized
        try {
            $tierData = $this->tierService->getTierData();
            if (empty($tierData['list'])) {
                $this->tierService->saveTierData([
                    'enabled' => true,
                    'list'    => [
                        ['id' => 'tier_bronze', 'name' => 'Bronze Member', 'points' => 0, 'multiplier' => 1.0],
                        ['id' => 'tier_silver', 'name' => 'Silver VIP', 'points' => 200, 'multiplier' => 1.25],
                        ['id' => 'tier_gold', 'name' => 'Gold VIP', 'points' => 500, 'multiplier' => 1.5],
                    ],
                ]);
            } else {
                // Ensure tiers are toggled on so progress bar displays
                $tierData['enabled'] = true;
                $this->tierService->saveTierData($tierData);
            }
        } catch (\Throwable $e) {
            // Non-critical tier configuration failure
        }

        // 5. Ensure sample promotional multiplier campaign exists
        try {
            $campaigns = $this->campaignService->getCampaigns();
            if (empty($campaigns)) {
                $this->campaignService->saveCampaigns([
                    [
                        'id'             => 'demo_weekend_2x',
                        'name'           => 'Double Points Weekend Event',
                        'multiplier'     => 2.0,
                        'scope'          => 'all',
                        'enabled'        => true,
                        'start_datetime' => gmdate('Y-m-d H:i:s', $now - 86400),
                        'end_datetime'   => gmdate('Y-m-d H:i:s', $now + (7 * 86400)),
                        'product_ids'    => [],
                        'category_ids'   => [],
                    ],
                ]);
            }
        } catch (\Throwable $e) {
            // Non-critical campaign configuration failure
        }

        return [
            'success'        => true,
            'inserted_count' => $insertedCount,
            'final_balance'  => $finalBalance,
            'message'        => sprintf(
                /* translators: 1: Number of transactions, 2: Final balance */
                __('Demo data successfully generated! Created %1$d transactions. Available balance is now %2$d points.', 'wpcalibrate-loyalty-rewards'),
                $insertedCount,
                $finalBalance
            ),
        ];
    }

    /**
     * Clear all demo transactions for a specific user, or all demo records.
     *
     * @param int $userId Target user ID (0 to purge for all users).
     * @return int Number of rows removed.
     */
    public function clearDemoData(int $userId = 0): int
    {
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb)) {
            return 0;
        }

        try {
            $table = Schema::getTableName();

            if ($userId > 0) {
                $deleted = $wpdb->query(
                    $wpdb->prepare(
                        "DELETE FROM {$table} WHERE user_id = %d AND event_key LIKE %s",
                        $userId,
                        'demo_%'
                    )
                );
                $this->balanceService->reconcile($userId);
            } else {
                // Find all affected user IDs to reconcile their balances
                $affectedUserIds = $wpdb->get_col("SELECT DISTINCT user_id FROM {$table} WHERE event_key LIKE 'demo_%'");
                $deleted = $wpdb->query("DELETE FROM {$table} WHERE event_key LIKE 'demo_%'");

                if (!empty($affectedUserIds)) {
                    foreach ($affectedUserIds as $uId) {
                        $this->balanceService->reconcile((int) $uId);
                    }
                }
            }

            return is_numeric($deleted) ? (int) $deleted : 0;
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * Check if a user currently has demo transactions active.
     */
    public function hasDemoData(int $userId): bool
    {
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb) || $userId <= 0) {
            return false;
        }

        try {
            $table = Schema::getTableName();
            $count = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND event_key LIKE %s",
                    $userId,
                    'demo_%'
                )
            );

            return (int) $count > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
