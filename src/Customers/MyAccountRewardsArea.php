<?php
declare(strict_types=1);

namespace WPCalibrate\LoyaltyRewards\Customers;

use WPCalibrate\LoyaltyRewards\Database\TransactionRepository;
use WPCalibrate\LoyaltyRewards\Points\BalanceService;
use WPCalibrate\LoyaltyRewards\Settings\SettingsManager;
use WPCalibrate\LoyaltyRewards\Tiers\TierService;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles the WooCommerce My Account 'rewards' endpoint registration and template rendering.
 */
class MyAccountRewardsArea
{
    public const ENDPOINT = 'rewards';

    private BalanceService $balanceService;
    private TransactionRepository $repository;
    private TierService $tierService;

    public function __construct(
        ?BalanceService $balanceService = null,
        ?TransactionRepository $repository = null,
        ?TierService $tierService = null
    ) {
        $this->balanceService = $balanceService ?? new BalanceService();
        $this->repository = $repository ?? new TransactionRepository();
        $this->tierService = $tierService ?? new TierService();
    }

    /**
     * Register My Account hooks.
     */
    public function register(): void
    {
        add_action('init', [$this, 'addEndpoint']);
        add_filter('query_vars', [$this, 'addQueryVars'], 0);
        add_filter('woocommerce_account_menu_items', [$this, 'addMenuItem'], 40);
        add_action('woocommerce_account_' . self::ENDPOINT . '_endpoint', [$this, 'renderEndpoint']);
    }

    /**
     * Add rewrite endpoint for My Account.
     */
    public function addEndpoint(): void
    {
        add_rewrite_endpoint(self::ENDPOINT, EP_ROOT | EP_PAGES);
    }

    /**
     * Add query vars.
     *
     * @param string[] $vars
     * @return string[]
     */
    public function addQueryVars(array $vars): array
    {
        $vars[] = self::ENDPOINT;
        $vars[] = 'rewards_page';
        return $vars;
    }

    /**
     * Insert 'Loyalty Rewards' tab into customer account navigation.
     *
     * @param array<string, string> $items
     * @return array<string, string>
     */
    public function addMenuItem(array $items): array
    {
        $newItems = [];
        foreach ($items as $key => $label) {
            $newItems[$key] = $label;
            if ($key === 'orders') {
                $newItems[self::ENDPOINT] = __('Loyalty Rewards', 'wpcalibrate-loyalty-rewards');
            }
        }

        if (!isset($newItems[self::ENDPOINT])) {
            $newItems[self::ENDPOINT] = __('Loyalty Rewards', 'wpcalibrate-loyalty-rewards');
        }

        return $newItems;
    }

    /**
     * Render the customer rewards endpoint view.
     */
    public function renderEndpoint(): void
    {
        $userId = get_current_user_id();
        if ($userId <= 0) {
            echo '<p>' . esc_html__('Please log in to view your loyalty rewards.', 'wpcalibrate-loyalty-rewards') . '</p>';
            return;
        }

        $balance = $this->balanceService->getBalance($userId);
        $ratio = (int) SettingsManager::get('redemption_ratio', 100);
        $rewardValue = round($balance / max(1, $ratio), 2);
        $pointLabelPlural = SettingsManager::get('point_label_plural', 'Points');

        $tierInfo = $this->tierService->getCustomerTier($userId);

        $expiringCutoff = gmdate('Y-m-d H:i:s', strtotime('+30 days'));
        $expiringPoints = $this->repository->getExpiringPoints($userId, $expiringCutoff);

        // Paginated customer transactions (strictly limited to authenticated user)
        $perPage = 10;
        $currentPage = max(1, absint($_GET['rewards_page'] ?? 1));
        $offset = ($currentPage - 1) * $perPage;

        $totalTransactions = $this->repository->countUserTransactions($userId);
        $totalPages = max(1, (int) ceil($totalTransactions / $perPage));
        $transactions = $this->repository->getUserTransactions($userId, $perPage, $offset);

        $template = WPCLR_PLUGIN_DIR . 'templates/myaccount/rewards.php';
        if (file_exists($template)) {
            include $template;
        }
    }
}
