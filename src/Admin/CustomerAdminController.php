<?php
declare(strict_types=1);

namespace WPCalibrate\LoyaltyRewards\Admin;

use WPCalibrate\LoyaltyRewards\Database\TransactionRepository;
use WPCalibrate\LoyaltyRewards\Points\BalanceService;
use WPCalibrate\LoyaltyRewards\Points\TransactionService;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles administrative customer rewards inspection, manual points adjustments, and balance reconciliation.
 */
class CustomerAdminController
{
    private BalanceService $balanceService;
    private TransactionService $transactionService;
    private TransactionRepository $repository;

    public function __construct(
        ?BalanceService $balanceService = null,
        ?TransactionService $transactionService = null,
        ?TransactionRepository $repository = null
    ) {
        $this->balanceService = $balanceService ?? new BalanceService();
        $this->transactionService = $transactionService ?? new TransactionService($this->repository, $this->balanceService);
        $this->repository = $repository ?? $this->transactionService->getRepository();
    }

    /**
     * Process customer adjustment or reconciliation actions.
     */
    public function handleActions(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        // 1. Manual Adjustment
        if (isset($_POST['wpclr_action']) && $_POST['wpclr_action'] === 'adjust_points') {
            check_admin_referer('wpclr_customer_action', 'wpclr_nonce');

            $userId = absint($_POST['user_id'] ?? 0);
            $points = (int) ($_POST['points'] ?? 0);
            $reason = sanitize_text_field((string) ($_POST['reason'] ?? ''));
            $orderId = !empty($_POST['order_id']) ? absint($_POST['order_id']) : null;

            if ($userId <= 0 || $points === 0 || empty($reason)) {
                AdminNotices::addError(__('Customer ID, non-zero points amount, and an audit reason are required.', 'wpcalibrate-loyalty-rewards'));
                return;
            }

            try {
                $adminId = get_current_user_id();
                $this->transactionService->manualAdjustment($userId, $points, $reason, $adminId, $orderId);
                AdminNotices::addSuccess(
                    sprintf(
                        /* translators: 1: Points adjusted, 2: Customer user ID */
                        __('Successfully adjusted %1$d points for customer #%2$d.', 'wpcalibrate-loyalty-rewards'),
                        $points,
                        $userId
                    )
                );
            } catch (\Throwable $e) {
                AdminNotices::addError(sprintf(__('Failed to adjust points: %s', 'wpcalibrate-loyalty-rewards'), $e->getMessage()));
            }
        }

        // 2. Reconcile Balance
        if (isset($_POST['wpclr_action']) && $_POST['wpclr_action'] === 'reconcile_balance') {
            check_admin_referer('wpclr_customer_action', 'wpclr_nonce');

            $userId = absint($_POST['user_id'] ?? 0);
            if ($userId > 0) {
                $result = $this->balanceService->reconcile($userId);
                if ($result['reconciled']) {
                    AdminNotices::addSuccess(
                        sprintf(
                            /* translators: 1: User ID, 2: Previous balance, 3: Corrected balance */
                            __('Customer #%1$d balance reconciled! Repaired mismatch from %2$d to %3$d points.', 'wpcalibrate-loyalty-rewards'),
                            $userId,
                            $result['previous_cached'],
                            $result['actual_ledger']
                        )
                    );
                } else {
                    AdminNotices::addSuccess(
                        sprintf(
                            /* translators: 1: User ID, 2: Confirmed balance */
                            __('Customer #%1$d balance is fully verified and matches the ledger (%2$d points).', 'wpcalibrate-loyalty-rewards'),
                            $userId,
                            $result['actual_ledger']
                        )
                    );
                }
            }
        }
    }

    /**
     * Search customers for administration table.
     *
     * @param string $search
     * @param int    $limit
     * @param int    $offset
     * @return array{total: int, users: array<int, array<string, mixed>>}
     */
    public function getCustomers(string $search = '', int $limit = 20, int $offset = 0): array
    {
        $args = [
            'number' => $limit,
            'offset' => $offset,
            'fields' => ['ID', 'user_login', 'user_email', 'display_name', 'user_registered'],
        ];

        if (!empty($search)) {
            $args['search'] = '*' . esc_attr($search) . '*';
            $args['search_columns'] = ['user_login', 'user_email', 'user_nicename', 'display_name'];
        }

        $query = new \WP_User_Query($args);
        $total = (int) $query->get_total();
        $users = [];

        foreach ($query->get_results() as $u) {
            $userId = (int) $u->ID;
            $users[] = [
                'id'           => $userId,
                'name'         => $u->display_name ?: $u->user_login,
                'email'        => $u->user_email,
                'registered'   => $u->user_registered,
                'balance'      => $this->balanceService->getBalance($userId),
                'lifetime_in'  => $this->repository->calculateLifetimeEarned($userId),
                'lifetime_out' => $this->repository->calculateLifetimeRedeemed($userId),
            ];
        }

        return [
            'total' => $total,
            'users' => $users,
        ];
    }
}
