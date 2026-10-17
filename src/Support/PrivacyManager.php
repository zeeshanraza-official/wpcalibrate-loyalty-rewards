<?php
declare(strict_types=1);

namespace WPCalibrate\LoyaltyRewards\Support;

use WPCalibrate\LoyaltyRewards\Database\Schema;
use WPCalibrate\LoyaltyRewards\Database\TransactionRepository;
use WPCalibrate\LoyaltyRewards\Points\BalanceService;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles WordPress privacy tools integration: suggested privacy policy content, personal data export, and audit-safe personal data erasure.
 */
class PrivacyManager
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
     * Register privacy hooks.
     */
    public function register(): void
    {
        add_action('admin_init', [$this, 'addPrivacyPolicyContent']);
        add_filter('wp_privacy_personal_data_exporters', [$this, 'registerDataExporter']);
        add_filter('wp_privacy_personal_data_erasers', [$this, 'registerDataEraser']);
    }

    /**
     * Add suggested privacy policy disclosure text.
     */
    public function addPrivacyPolicyContent(): void
    {
        if (!function_exists('wp_add_privacy_policy_content')) {
            return;
        }

        $content = sprintf(
            '<h2>%s</h2><p>%s</p><ul><li>%s</li><li>%s</li><li>%s</li></ul>',
            esc_html__('Loyalty & Rewards Program', 'wpcalibrate-loyalty-rewards'),
            esc_html__('We operate a loyalty rewards program. When you make purchases, write reviews, or participate in promotional campaigns, we collect and store reward transactions linked to your account.', 'wpcalibrate-loyalty-rewards'),
            esc_html__('Your loyalty points balance and transaction history (points earned, redeemed, expired, and adjustments).', 'wpcalibrate-loyalty-rewards'),
            esc_html__('Order references associated with earned and redeemed points.', 'wpcalibrate-loyalty-rewards'),
            esc_html__('Transactional email records notifying you of points balance updates and expiration alerts.', 'wpcalibrate-loyalty-rewards')
        );

        wp_add_privacy_policy_content('WPCalibrate Loyalty & Rewards', $content);
    }

    /**
     * Register personal data exporter.
     *
     * @param array<string, mixed> $exporters
     * @return array<string, mixed>
     */
    public function registerDataExporter(array $exporters): array
    {
        $exporters['wpcalibrate-loyalty-rewards'] = [
            'exporter_friendly_name' => __('WPCalibrate Loyalty & Rewards Data', 'wpcalibrate-loyalty-rewards'),
            'callback'               => [$this, 'exportPersonalData'],
        ];

        return $exporters;
    }

    /**
     * Export customer loyalty data for WordPress privacy request.
     *
     * @param string $emailAddress
     * @param int    $page
     * @return array{data: array<int, array<string, mixed>>, done: bool}
     */
    public function exportPersonalData(string $emailAddress, int $page = 1): array
    {
        $user = get_user_by('email', $emailAddress);
        if (!$user) {
            return ['data' => [], 'done' => true];
        }

        $userId = (int) $user->ID;
        $exportItems = [];

        // Summary item
        if ($page === 1) {
            $balance = $this->balanceService->getBalance($userId);
            $exportItems[] = [
                'group_id'    => 'wpclr_loyalty_summary',
                'group_label' => __('Loyalty Rewards Summary', 'wpcalibrate-loyalty-rewards'),
                'item_id'     => "user-{$userId}",
                'data'        => [
                    [
                        'name'  => __('Current Point Balance', 'wpcalibrate-loyalty-rewards'),
                        'value' => $balance,
                    ],
                    [
                        'name'  => __('Lifetime Points Earned', 'wpcalibrate-loyalty-rewards'),
                        'value' => $this->repository->calculateLifetimeEarned($userId),
                    ],
                    [
                        'name'  => __('Lifetime Points Redeemed', 'wpcalibrate-loyalty-rewards'),
                        'value' => $this->repository->calculateLifetimeRedeemed($userId),
                    ],
                ],
            ];
        }

        // Ledger transactions
        $limit = 50;
        $offset = ($page - 1) * $limit;
        $transactions = $this->repository->getUserTransactions($userId, $limit, $offset);

        foreach ($transactions as $tx) {
            $exportItems[] = [
                'group_id'    => 'wpclr_loyalty_transactions',
                'group_label' => __('Loyalty Transactions', 'wpcalibrate-loyalty-rewards'),
                'item_id'     => "tx-{$tx['id']}",
                'data'        => [
                    ['name' => __('Date', 'wpcalibrate-loyalty-rewards'), 'value' => $tx['created_at']],
                    ['name' => __('Type', 'wpcalibrate-loyalty-rewards'), 'value' => $tx['transaction_type']],
                    ['name' => __('Points', 'wpcalibrate-loyalty-rewards'), 'value' => $tx['points']],
                    ['name' => __('Description', 'wpcalibrate-loyalty-rewards'), 'value' => $tx['description'] ?? ''],
                    ['name' => __('Order ID', 'wpcalibrate-loyalty-rewards'), 'value' => $tx['order_id'] ?? ''],
                ],
            ];
        }

        $done = count($transactions) < $limit;

        return [
            'data' => $exportItems,
            'done' => $done,
        ];
    }

    /**
     * Register personal data eraser.
     *
     * @param array<string, mixed> $erasers
     * @return array<string, mixed>
     */
    public function registerDataEraser(array $erasers): array
    {
        $erasers['wpcalibrate-loyalty-rewards'] = [
            'eraser_friendly_name' => __('WPCalibrate Loyalty & Rewards Data', 'wpcalibrate-loyalty-rewards'),
            'callback'              => [$this, 'erasePersonalData'],
        ];

        return $erasers;
    }

    /**
     * Erase/Anonymize personal data without destroying historical accounting audit totals.
     *
     * @param string $emailAddress
     * @param int    $page
     * @return array{items_removed: int, items_retained: int, messages: string[], done: bool}
     */
    public function erasePersonalData(string $emailAddress, int $page = 1): array
    {
        $user = get_user_by('email', $emailAddress);
        if (!$user) {
            return [
                'items_removed'  => 0,
                'items_retained' => 0,
                'messages'       => [],
                'done'           => true,
            ];
        }

        $userId = (int) $user->ID;

        // Remove plugin-specific user meta
        delete_user_meta($userId, BalanceService::USER_META_BALANCE);
        delete_user_meta($userId, 'wpclr_last_expiry_warning_date');

        // Anonymize personal metadata in ledger without breaking accounting totals
        global $wpdb;
        $table = Schema::getTableName();
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$table} SET description = %s, metadata = NULL WHERE user_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                __('Anonymized transaction (customer requested erasure)', 'wpcalibrate-loyalty-rewards'),
                $userId
            )
        );

        return [
            'items_removed'  => 1,
            'items_retained' => 0,
            'messages'       => [__('Customer loyalty records have been anonymized.', 'wpcalibrate-loyalty-rewards')],
            'done'           => true,
        ];
    }
}
