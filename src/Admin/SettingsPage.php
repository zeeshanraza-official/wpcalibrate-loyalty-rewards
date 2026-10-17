<?php
declare(strict_types=1);

namespace WPCalibrate\LoyaltyRewards\Admin;

use WPCalibrate\LoyaltyRewards\Campaigns\CampaignService;
use WPCalibrate\LoyaltyRewards\Database\TransactionRepository;
use WPCalibrate\LoyaltyRewards\Expiration\ExpirationService;
use WPCalibrate\LoyaltyRewards\Licensing\LicenseService;
use WPCalibrate\LoyaltyRewards\Points\BalanceService;
use WPCalibrate\LoyaltyRewards\Reports\ReportService;
use WPCalibrate\LoyaltyRewards\Settings\SettingsManager;
use WPCalibrate\LoyaltyRewards\Support\DemoDataService;
use WPCalibrate\LoyaltyRewards\Support\SupportInfo;
use WPCalibrate\LoyaltyRewards\Tiers\TierService;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Main administration settings page renderer and action dispatcher.
 */
class SettingsPage
{
    private CampaignService $campaignService;
    private TierService $tierService;
    private CustomerAdminController $customerController;
    private TransactionAdminController $transactionController;
    private ReportService $reportService;
    private LicenseService $licenseService;
    private ExpirationService $expirationService;
    private BalanceService $balanceService;
    private TransactionRepository $repository;
    private DemoDataService $demoDataService;

    public function __construct(
        ?CampaignService $campaignService = null,
        ?TierService $tierService = null,
        ?CustomerAdminController $customerController = null,
        ?TransactionAdminController $transactionController = null,
        ?ReportService $reportService = null,
        ?LicenseService $licenseService = null,
        ?ExpirationService $expirationService = null,
        ?BalanceService $balanceService = null,
        ?TransactionRepository $repository = null,
        ?DemoDataService $demoDataService = null
    ) {
        $this->campaignService = $campaignService ?? new CampaignService();
        $this->tierService = $tierService ?? new TierService();
        $this->customerController = $customerController ?? new CustomerAdminController();
        $this->transactionController = $transactionController ?? new TransactionAdminController();
        $this->reportService = $reportService ?? new ReportService();
        $this->licenseService = $licenseService ?? new LicenseService();
        $this->expirationService = $expirationService ?? new ExpirationService();
        $this->balanceService = $balanceService ?? new BalanceService();
        $this->repository = $repository ?? new TransactionRepository();
        $this->demoDataService = $demoDataService ?? new DemoDataService(
            $this->repository,
            $this->balanceService,
            $this->tierService,
            $this->campaignService
        );
    }

    /**
     * Dispatch administrative POST requests.
     */
    public function handlePostActions(): void
    {
        if (!current_user_can('manage_woocommerce') || empty($_POST['wpclr_admin_action'])) {
            return;
        }

        $action = sanitize_key($_POST['wpclr_admin_action']);

        // 1. Save Settings
        if ($action === 'save_settings') {
            check_admin_referer('wpclr_settings_save', 'wpclr_nonce');
            $settingsInput = $_POST['wpclr_settings'] ?? [];
            if (is_array($settingsInput)) {
                if (!empty($_POST['wpclr_settings_section'])) {
                    $settingsInput['wpclr_settings_section'] = sanitize_key($_POST['wpclr_settings_section']);
                } elseif (!empty($_GET['tab'])) {
                    $settingsInput['wpclr_settings_section'] = sanitize_key($_GET['tab']);
                }
                SettingsManager::updateAll($settingsInput);
                AdminNotices::addSuccess(__('Settings updated successfully.', 'wpcalibrate-loyalty-rewards'));
            }
        }

        // 2. Save Campaigns
        if ($action === 'save_campaign') {
            check_admin_referer('wpclr_campaign_action', 'wpclr_nonce');
            $campaigns = $this->campaignService->getCampaigns();

            $id = sanitize_key($_POST['campaign_id'] ?? ('camp_' . time()));
            $name = sanitize_text_field($_POST['campaign_name'] ?? '');
            $multiplier = max(1.0, (float) ($_POST['multiplier'] ?? 2.0));
            $scope = in_array($_POST['scope'] ?? '', ['all', 'products', 'categories'], true) ? $_POST['scope'] : 'all';
            $enabled = !empty($_POST['enabled']);
            $start = !empty($_POST['start_datetime']) ? sanitize_text_field($_POST['start_datetime']) : null;
            $end = !empty($_POST['end_datetime']) ? sanitize_text_field($_POST['end_datetime']) : null;

            $productIds = !empty($_POST['product_ids']) ? array_map('absint', explode(',', (string) $_POST['product_ids'])) : [];
            $categoryIds = !empty($_POST['category_ids']) ? array_map('absint', explode(',', (string) $_POST['category_ids'])) : [];

            $newCampaign = [
                'id'             => $id,
                'name'           => $name ?: __('Special Campaign', 'wpcalibrate-loyalty-rewards'),
                'multiplier'     => $multiplier,
                'scope'          => $scope,
                'enabled'        => $enabled,
                'start_datetime' => $start,
                'end_datetime'   => $end,
                'product_ids'    => $productIds,
                'category_ids'   => $categoryIds,
            ];

            // Update existing or append
            $found = false;
            foreach ($campaigns as $k => $c) {
                if (($c['id'] ?? '') === $id) {
                    $campaigns[$k] = $newCampaign;
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $campaigns[] = $newCampaign;
            }

            $this->campaignService->saveCampaigns($campaigns);
            AdminNotices::addSuccess(__('Campaign saved successfully.', 'wpcalibrate-loyalty-rewards'));
        }

        // 3. Delete Campaign
        if ($action === 'delete_campaign') {
            check_admin_referer('wpclr_campaign_action', 'wpclr_nonce');
            $idToDelete = sanitize_key($_POST['delete_campaign_id'] ?? '');
            $campaigns = $this->campaignService->getCampaigns();
            $campaigns = array_filter($campaigns, static function ($c) use ($idToDelete): bool {
                return ($c['id'] ?? '') !== $idToDelete;
            });
            $this->campaignService->saveCampaigns($campaigns);
            AdminNotices::addSuccess(__('Campaign deleted.', 'wpcalibrate-loyalty-rewards'));
        }

        // 4. Save Tiers
        if ($action === 'save_tiers') {
            check_admin_referer('wpclr_tiers_action', 'wpclr_nonce');
            $enabled = !empty($_POST['tiers_enabled']);
            $rawTiers = $_POST['tiers'] ?? [];
            $cleanList = [];

            if (is_array($rawTiers)) {
                foreach ($rawTiers as $t) {
                    if (empty($t['name'])) {
                        continue;
                    }
                    $cleanList[] = [
                        'id'         => sanitize_key($t['id'] ?? sanitize_title($t['name'])),
                        'name'       => sanitize_text_field($t['name']),
                        'points'     => max(0, absint($t['points'] ?? 0)),
                        'multiplier' => max(1.0, (float) ($t['multiplier'] ?? 1.0)),
                    ];
                }
            }

            $this->tierService->saveTierData([
                'enabled' => $enabled,
                'list'    => $cleanList,
            ]);
            AdminNotices::addSuccess(__('Loyalty tiers updated.', 'wpcalibrate-loyalty-rewards'));
        }

        // 5. Licensing Actions
        if ($action === 'activate_license') {
            check_admin_referer('wpclr_license_action', 'wpclr_nonce');
            $key = sanitize_text_field($_POST['license_key'] ?? '');
            $result = $this->licenseService->activate($key);
            if ($result->isSuccess()) {
                AdminNotices::addSuccess($result->getMessage());
            } else {
                AdminNotices::addError($result->getMessage());
            }
        } elseif ($action === 'deactivate_license') {
            check_admin_referer('wpclr_license_action', 'wpclr_nonce');
            $result = $this->licenseService->deactivate();
            AdminNotices::addSuccess($result->getMessage());
        }

        // 6. Tools Actions
        if ($action === 'process_expiry_now') {
            check_admin_referer('wpclr_tools_action', 'wpclr_nonce');
            $expiredCount = $this->expirationService->processExpiredPointsBatch(200);
            AdminNotices::addSuccess(
                sprintf(
                    /* translators: %d: Count of processed transactions */
                    __('Processed expiration batch: %d transactions expired.', 'wpcalibrate-loyalty-rewards'),
                    $expiredCount
                )
            );
        }

        // 7. Demo Data Showcase Actions
        if ($action === 'generate_demo_data') {
            check_admin_referer('wpclr_demo_action', 'wpclr_nonce');
            $targetUserId = !empty($_POST['demo_user_id']) ? absint($_POST['demo_user_id']) : get_current_user_id();
            try {
                $result = $this->demoDataService->generateDemoData($targetUserId);
                if (!empty($result['success'])) {
                    AdminNotices::addSuccess($result['message']);
                } else {
                    AdminNotices::addError($result['message']);
                }
            } catch (\Throwable $e) {
                AdminNotices::addError(sprintf(
                    /* translators: %s: Error message */
                    __('Error generating demo data: %s', 'wpcalibrate-loyalty-rewards'),
                    $e->getMessage()
                ));
            }
        }

        if ($action === 'clear_demo_data') {
            check_admin_referer('wpclr_demo_action', 'wpclr_nonce');
            $targetUserId = !empty($_POST['demo_user_id']) ? absint($_POST['demo_user_id']) : get_current_user_id();
            try {
                $deletedCount = $this->demoDataService->clearDemoData($targetUserId);
                AdminNotices::addSuccess(sprintf(
                    /* translators: %d: Count of removed demo records */
                    __('Demo loyalty transactions successfully cleared (%d records removed). Account balance reconciled.', 'wpcalibrate-loyalty-rewards'),
                    $deletedCount
                ));
            } catch (\Throwable $e) {
                AdminNotices::addError(sprintf(
                    /* translators: %s: Error message */
                    __('Error clearing demo data: %s', 'wpcalibrate-loyalty-rewards'),
                    $e->getMessage()
                ));
            }
        }

        // Delegate customer actions
        $this->customerController->handleActions();
    }

    /**
     * Render the administrative view.
     */
    public function render(): void
    {
        $this->handlePostActions();
        $this->transactionController->handleExport();

        $allowedTabs = [
            'overview',
            'earning',
            'redemption',
            'expiry',
            'campaigns',
            'tiers',
            'customers',
            'transactions',
            'emails',
            'reports',
            'tools',
            'license',
            'advanced',
            'support',
        ];

        $currentTab = sanitize_key($_GET['tab'] ?? 'overview');
        if (!in_array($currentTab, $allowedTabs, true)) {
            $currentTab = 'overview';
        }

        ?>
        <div class="wrap wpclr-admin-wrap">
            <!-- Header Banner -->
            <div class="wpclr-admin-header">
                <div class="wpclr-admin-brand">
                    <img src="<?php echo esc_url(plugins_url('branding/icon-dark.png', WPCLR_PLUGIN_FILE)); ?>" alt="WPCalibrate" class="wpclr-brand-logo" />
                    <div>
                        <h1 class="wpclr-plugin-title">WPCalibrate <span class="wpclr-plugin-subtitle">Loyalty & Rewards</span></h1>
                        <span class="wpclr-version-badge">v<?php echo esc_html(WPCLR_VERSION); ?></span>
                    </div>
                </div>
                <div class="wpclr-header-actions">
                    <a href="<?php echo esc_url(SupportInfo::WHATSAPP_URL); ?>" target="_blank" rel="noopener noreferrer" class="button button-secondary wpclr-btn-wa">
                        💬 <?php esc_html_e('WhatsApp Support', 'wpcalibrate-loyalty-rewards'); ?>
                    </a>
                </div>
            </div>

            <!-- Admin Flash Notices -->
            <?php AdminNotices::render(); ?>

            <!-- Navigation Tabs -->
            <nav class="wpclr-nav-tabs" role="tablist" aria-label="<?php esc_attr_e('Plugin Sections', 'wpcalibrate-loyalty-rewards'); ?>">
                <?php
                $tabLabels = [
                    'overview'     => __('Overview', 'wpcalibrate-loyalty-rewards'),
                    'earning'      => __('Earning', 'wpcalibrate-loyalty-rewards'),
                    'redemption'   => __('Redemption', 'wpcalibrate-loyalty-rewards'),
                    'expiry'       => __('Expiry', 'wpcalibrate-loyalty-rewards'),
                    'campaigns'    => __('Campaigns', 'wpcalibrate-loyalty-rewards'),
                    'tiers'        => __('Tiers', 'wpcalibrate-loyalty-rewards'),
                    'customers'    => __('Customers', 'wpcalibrate-loyalty-rewards'),
                    'transactions' => __('Transactions', 'wpcalibrate-loyalty-rewards'),
                    'emails'       => __('Emails', 'wpcalibrate-loyalty-rewards'),
                    'reports'      => __('Reports', 'wpcalibrate-loyalty-rewards'),
                    'tools'        => __('Tools', 'wpcalibrate-loyalty-rewards'),
                    'license'      => __('License', 'wpcalibrate-loyalty-rewards'),
                    'advanced'     => __('Advanced', 'wpcalibrate-loyalty-rewards'),
                    'support'      => __('Support', 'wpcalibrate-loyalty-rewards'),
                ];

                foreach ($tabLabels as $tabKey => $tabTitle) {
                    $tabUrl = add_query_arg(['page' => AdminMenu::PAGE_SLUG, 'tab' => $tabKey], admin_url('admin.php'));
                    $isActive = ($currentTab === $tabKey);
                    printf(
                        '<a href="%s" class="wpclr-tab-link %s" role="tab" aria-selected="%s">%s</a>',
                        esc_url($tabUrl),
                        $isActive ? 'active' : '',
                        $isActive ? 'true' : 'false',
                        esc_html($tabTitle)
                    );
                }
                ?>
            </nav>

            <!-- Tab Content Panel -->
            <div class="wpclr-tab-panel">
                <?php
                switch ($currentTab) {
                    case 'overview':
                        $this->renderOverviewTab();
                        break;
                    case 'earning':
                        $this->renderEarningTab();
                        break;
                    case 'redemption':
                        $this->renderRedemptionTab();
                        break;
                    case 'expiry':
                        $this->renderExpiryTab();
                        break;
                    case 'campaigns':
                        $this->renderCampaignsTab();
                        break;
                    case 'tiers':
                        $this->renderTiersTab();
                        break;
                    case 'customers':
                        $this->renderCustomersTab();
                        break;
                    case 'transactions':
                        $this->renderTransactionsTab();
                        break;
                    case 'emails':
                        $this->renderEmailsTab();
                        break;
                    case 'reports':
                        $this->renderReportsTab();
                        break;
                    case 'tools':
                        $this->renderToolsTab();
                        break;
                    case 'license':
                        $this->renderLicenseTab();
                        break;
                    case 'advanced':
                        $this->renderAdvancedTab();
                        break;
                    case 'support':
                        $this->renderSupportTab();
                        break;
                }
                ?>
            </div>
        </div>
        <?php
    }

    /**
     * Tab: Overview
     *
     * Provides an executive dashboard introducing the plugin's purpose,
     * quickstart usage instructions, financial ledger methodology, live KPIs,
     * and quick actions.
     */
    private function renderOverviewTab(): void
    {
        $stats = $this->reportService->getSummaryStats();
        $recentTransactions = $this->repository->getTransactions(['limit' => 5, 'orderby' => 'id', 'order' => 'DESC']);
        $activeCampaigns = $this->campaignService->getActiveCampaigns();
        $tierEnabled = $this->tierService->isEnabled();
        ?>
        <div class="wpclr-overview-view">
            <!-- Hero Introduction & Quick Action Shortcuts -->
            <div class="wpclr-overview-hero">
                <h2 class="wpclr-hero-title">
                    <span>👑</span> <?php esc_html_e('Welcome to WPCalibrate Loyalty & Rewards', 'wpcalibrate-loyalty-rewards'); ?>
                </h2>
                <p class="wpclr-hero-desc">
                    <?php esc_html_e('WPCalibrate Loyalty & Rewards turns casual WooCommerce shoppers into high-LTV brand advocates. Encourage repeat purchases, gamify customer retention with VIP tiers, and protect your store margins with our immutable financial ledger and automated liability controls.', 'wpcalibrate-loyalty-rewards'); ?>
                </p>
                <div class="wpclr-hero-actions">
                    <a href="<?php echo esc_url(add_query_arg(['page' => AdminMenu::PAGE_SLUG, 'tab' => 'earning'], admin_url('admin.php'))); ?>" class="wpclr-hero-btn wpclr-hero-btn-primary">
                        ⚙️ <?php esc_html_e('Configure Earning', 'wpcalibrate-loyalty-rewards'); ?>
                    </a>
                    <a href="<?php echo esc_url(add_query_arg(['page' => AdminMenu::PAGE_SLUG, 'tab' => 'redemption'], admin_url('admin.php'))); ?>" class="wpclr-hero-btn">
                        🎁 <?php esc_html_e('Configure Redemption', 'wpcalibrate-loyalty-rewards'); ?>
                    </a>
                    <a href="<?php echo esc_url(add_query_arg(['page' => AdminMenu::PAGE_SLUG, 'tab' => 'campaigns'], admin_url('admin.php'))); ?>" class="wpclr-hero-btn">
                        🚀 <?php esc_html_e('Promotional Multipliers', 'wpcalibrate-loyalty-rewards'); ?>
                    </a>
                    <a href="<?php echo esc_url(add_query_arg(['page' => AdminMenu::PAGE_SLUG, 'tab' => 'tiers'], admin_url('admin.php'))); ?>" class="wpclr-hero-btn">
                        💎 <?php esc_html_e('VIP Tiers', 'wpcalibrate-loyalty-rewards'); ?>
                    </a>
                    <a href="<?php echo esc_url(add_query_arg(['page' => AdminMenu::PAGE_SLUG, 'tab' => 'customers'], admin_url('admin.php'))); ?>" class="wpclr-hero-btn">
                        👥 <?php esc_html_e('Customer Balances', 'wpcalibrate-loyalty-rewards'); ?>
                    </a>
                </div>
            </div>

            <!-- Real-time KPI Cards -->
            <div class="wpclr-kpi-grid">
                <div class="wpclr-kpi-card">
                    <span class="wpclr-kpi-icon">📈</span>
                    <div class="wpclr-kpi-data">
                        <div class="wpclr-kpi-title"><?php esc_html_e('Total Points Issued', 'wpcalibrate-loyalty-rewards'); ?></div>
                        <div class="wpclr-kpi-val text-success"><?php echo esc_html(number_format_i18n($stats['total_issued'])); ?></div>
                        <span class="wpclr-kpi-sub"><?php esc_html_e('Cumulative points awarded', 'wpcalibrate-loyalty-rewards'); ?></span>
                    </div>
                </div>

                <div class="wpclr-kpi-card">
                    <span class="wpclr-kpi-icon">💎</span>
                    <div class="wpclr-kpi-data">
                        <div class="wpclr-kpi-title"><?php esc_html_e('Outstanding Points (Liability)', 'wpcalibrate-loyalty-rewards'); ?></div>
                        <div class="wpclr-kpi-val text-primary"><?php echo esc_html(number_format_i18n($stats['total_outstanding'])); ?></div>
                        <span class="wpclr-kpi-sub"><?php echo wc_price($stats['outstanding_monetary_value']); ?> <?php esc_html_e('unredeemed balance', 'wpcalibrate-loyalty-rewards'); ?></span>
                    </div>
                </div>

                <div class="wpclr-kpi-card">
                    <span class="wpclr-kpi-icon">🎁</span>
                    <div class="wpclr-kpi-data">
                        <div class="wpclr-kpi-title"><?php esc_html_e('Points Redeemed', 'wpcalibrate-loyalty-rewards'); ?></div>
                        <div class="wpclr-kpi-val text-warning"><?php echo esc_html(number_format_i18n($stats['total_redeemed'])); ?></div>
                        <span class="wpclr-kpi-sub"><?php esc_html_e('Used on cart discounts', 'wpcalibrate-loyalty-rewards'); ?></span>
                    </div>
                </div>

                <div class="wpclr-kpi-card">
                    <span class="wpclr-kpi-icon">⏳</span>
                    <div class="wpclr-kpi-data">
                        <div class="wpclr-kpi-title"><?php esc_html_e('Points Expired', 'wpcalibrate-loyalty-rewards'); ?></div>
                        <div class="wpclr-kpi-val text-danger"><?php echo esc_html(number_format_i18n($stats['total_expired'])); ?></div>
                        <span class="wpclr-kpi-sub"><?php esc_html_e('Cleared via FIFO expiry', 'wpcalibrate-loyalty-rewards'); ?></span>
                    </div>
                </div>
            </div>

            <!-- Card 1: Plugin Purpose & Strategic Benefits -->
            <div class="wpclr-card" style="margin-top: 24px;">
                <h3>🎯 <?php esc_html_e('Plugin Purpose & Strategic Objectives', 'wpcalibrate-loyalty-rewards'); ?></h3>
                <p style="color: var(--wpclr-text-muted); margin-bottom: 0;">
                    <?php esc_html_e('Why integrate WPCalibrate Loyalty & Rewards into your WooCommerce store? Here is how each mechanism drives store profitability and customer lifetime engagement:', 'wpcalibrate-loyalty-rewards'); ?>
                </p>
                <div class="wpclr-purpose-grid">
                    <div class="wpclr-purpose-item">
                        <span class="wpclr-purpose-icon">🚀</span>
                        <h4><?php esc_html_e('Maximize Customer LTV', 'wpcalibrate-loyalty-rewards'); ?></h4>
                        <p><?php esc_html_e('Rewarding purchases turns first-time buyers into repeat clients. Points accumulate automatically, creating an enduring incentive to repurchase.', 'wpcalibrate-loyalty-rewards'); ?></p>
                    </div>

                    <div class="wpclr-purpose-item">
                        <span class="wpclr-purpose-icon">🛍️</span>
                        <h4><?php esc_html_e('Increase Average Order Value', 'wpcalibrate-loyalty-rewards'); ?></h4>
                        <p><?php esc_html_e('Customers add extra items to cross earning thresholds, unlock higher VIP multipliers, or earn bonus points on promotional campaigns.', 'wpcalibrate-loyalty-rewards'); ?></p>
                    </div>

                    <div class="wpclr-purpose-item">
                        <span class="wpclr-purpose-icon">🏆</span>
                        <h4><?php esc_html_e('Gamified VIP Retention', 'wpcalibrate-loyalty-rewards'); ?></h4>
                        <p><?php esc_html_e('Segment high-spending customers into Bronze, Silver, and Gold tiers with exclusive multiplier rates that make leaving your store unprofitable.', 'wpcalibrate-loyalty-rewards'); ?></p>
                    </div>

                    <div class="wpclr-purpose-item">
                        <span class="wpclr-purpose-icon">🛡️</span>
                        <h4><?php esc_html_e('Strict Margin Protection', 'wpcalibrate-loyalty-rewards'); ?></h4>
                        <p><?php esc_html_e('Exclude taxes, shipping, and sale items from point calculation. Enforce maximum cart discount caps and automated FIFO points expiration.', 'wpcalibrate-loyalty-rewards'); ?></p>
                    </div>
                </div>
            </div>

            <!-- Card 2: How To Use / 5-Step Quickstart & Storefront Guide -->
            <div class="wpclr-card" style="margin-top: 24px;">
                <h3>📖 <?php esc_html_e('How To Use — 5-Step Quickstart & Storefront Guide', 'wpcalibrate-loyalty-rewards'); ?></h3>
                <p style="color: var(--wpclr-text-muted); margin-bottom: 0;">
                    <?php esc_html_e('Follow these five essential steps to configure your rules, launch promotional campaigns, and experience loyalty redemptions live across your storefront:', 'wpcalibrate-loyalty-rewards'); ?>
                </p>
                <div class="wpclr-guide-steps">
                    <!-- Step 1 -->
                    <div class="wpclr-step-card">
                        <div>
                            <div class="wpclr-step-header">
                                <span class="wpclr-step-badge">1</span>
                                <h4><?php esc_html_e('Set Earning Rules', 'wpcalibrate-loyalty-rewards'); ?></h4>
                            </div>
                            <div class="wpclr-step-body">
                                <?php esc_html_e('Define your base earning rate (e.g., 1 point per $1 spent). Choose which order statuses grant points and toggle exclusions for taxes, shipping, or discounted products.', 'wpcalibrate-loyalty-rewards'); ?>
                            </div>
                        </div>
                        <a href="<?php echo esc_url(add_query_arg(['page' => AdminMenu::PAGE_SLUG, 'tab' => 'earning'], admin_url('admin.php'))); ?>" class="wpclr-step-action">
                            <?php esc_html_e('Configure Earning Tab', 'wpcalibrate-loyalty-rewards'); ?> &rarr;
                        </a>
                    </div>

                    <!-- Step 2 -->
                    <div class="wpclr-step-card">
                        <div>
                            <div class="wpclr-step-header">
                                <span class="wpclr-step-badge">2</span>
                                <h4><?php esc_html_e('Define Redemption', 'wpcalibrate-loyalty-rewards'); ?></h4>
                            </div>
                            <div class="wpclr-step-body">
                                <?php esc_html_e('Set the points-to-currency value (e.g., 100 points = $1 store discount). Specify maximum allowable cart discount percentage to safeguard profit margins.', 'wpcalibrate-loyalty-rewards'); ?>
                            </div>
                        </div>
                        <a href="<?php echo esc_url(add_query_arg(['page' => AdminMenu::PAGE_SLUG, 'tab' => 'redemption'], admin_url('admin.php'))); ?>" class="wpclr-step-action">
                            <?php esc_html_e('Configure Redemption Tab', 'wpcalibrate-loyalty-rewards'); ?> &rarr;
                        </a>
                    </div>

                    <!-- Step 3 -->
                    <div class="wpclr-step-card">
                        <div>
                            <div class="wpclr-step-header">
                                <span class="wpclr-step-badge">3</span>
                                <h4><?php esc_html_e('Add Multipliers & Tiers', 'wpcalibrate-loyalty-rewards'); ?></h4>
                            </div>
                            <div class="wpclr-step-body">
                                <?php esc_html_e('Run promotional point multipliers (e.g. 2x weekend bonus) and build progressive VIP Tiers with automatic customer promotions based on rolling spend.', 'wpcalibrate-loyalty-rewards'); ?>
                            </div>
                        </div>
                        <a href="<?php echo esc_url(add_query_arg(['page' => AdminMenu::PAGE_SLUG, 'tab' => 'campaigns'], admin_url('admin.php'))); ?>" class="wpclr-step-action">
                            <?php esc_html_e('Campaigns & Tiers Tabs', 'wpcalibrate-loyalty-rewards'); ?> &rarr;
                        </a>
                    </div>

                    <!-- Step 4 -->
                    <div class="wpclr-step-card">
                        <div>
                            <div class="wpclr-step-header">
                                <span class="wpclr-step-badge">4</span>
                                <h4><?php esc_html_e('Engage & Monitor', 'wpcalibrate-loyalty-rewards'); ?></h4>
                            </div>
                            <div class="wpclr-step-body">
                                <?php esc_html_e('Enable automated transactional customer emails (points earned, redeemed, expiring), review balance sheets, and monitor customer activity in the live ledger.', 'wpcalibrate-loyalty-rewards'); ?>
                            </div>
                        </div>
                        <a href="<?php echo esc_url(add_query_arg(['page' => AdminMenu::PAGE_SLUG, 'tab' => 'emails'], admin_url('admin.php'))); ?>" class="wpclr-step-action">
                            <?php esc_html_e('Emails & Reports Tabs', 'wpcalibrate-loyalty-rewards'); ?> &rarr;
                        </a>
                    </div>

                    <!-- Step 5 -->
                    <div class="wpclr-step-card">
                        <div>
                            <div class="wpclr-step-header">
                                <span class="wpclr-step-badge">5</span>
                                <h4><?php esc_html_e('See Output on Website', 'wpcalibrate-loyalty-rewards'); ?></h4>
                            </div>
                            <div class="wpclr-step-body">
                                <p style="margin-top: 0; margin-bottom: 8px;">
                                    <?php esc_html_e('Experience redemptions and active campaigns live on your storefront:', 'wpcalibrate-loyalty-rewards'); ?>
                                </p>
                                <ul class="wpclr-step-list">
                                    <li>
                                        <strong><?php esc_html_e('Cart & Checkout:', 'wpcalibrate-loyalty-rewards'); ?></strong>
                                        <?php esc_html_e('Logged-in customers see the "Redeem Loyalty Rewards" widget above the Cart table and Checkout form (or Cart/Checkout Blocks) to apply instant discounts.', 'wpcalibrate-loyalty-rewards'); ?>
                                    </li>
                                    <li>
                                        <strong><?php esc_html_e('My Account Portal:', 'wpcalibrate-loyalty-rewards'); ?></strong>
                                        <?php esc_html_e('The "Loyalty Rewards" tab (/my-account/rewards/) displays live points balance, dollar worth, VIP tier progression, and ledger history.', 'wpcalibrate-loyalty-rewards'); ?>
                                    </li>
                                    <li>
                                        <strong><?php esc_html_e('Campaign Multipliers:', 'wpcalibrate-loyalty-rewards'); ?></strong>
                                        <?php esc_html_e('Active promotional campaigns automatically award 2x or 3x bonus points on qualifying orders at checkout.', 'wpcalibrate-loyalty-rewards'); ?>
                                    </li>
                                </ul>
                                <div style="margin-top: 14px; padding: 10px 12px; background: var(--wpclr-bg-subtle); border-radius: 6px; border: 1px dashed var(--wpclr-primary);">
                                    <div style="font-size: 12px; font-weight: 600; color: var(--wpclr-text-main); margin-bottom: 6px;">
                                        ⚡ <?php esc_html_e('Quick Test Storefront Output:', 'wpcalibrate-loyalty-rewards'); ?>
                                    </div>
                                    <form method="post" action="" style="display: inline-block; margin-bottom: 4px;">
                                        <?php wp_nonce_field('wpclr_demo_action', 'wpclr_nonce'); ?>
                                        <input type="hidden" name="wpclr_admin_action" value="generate_demo_data" />
                                        <button type="submit" class="button button-small button-primary" style="margin-right: 6px;">
                                            ✨ <?php esc_html_e('Generate Demo Data', 'wpcalibrate-loyalty-rewards'); ?>
                                        </button>
                                    </form>
                                    <?php if ($this->demoDataService->hasDemoData(get_current_user_id())) : ?>
                                        <form method="post" action="" style="display: inline-block; margin-bottom: 4px;" onsubmit="return confirm('<?php echo esc_js(__('Are you sure you want to clear demo transactions?', 'wpcalibrate-loyalty-rewards')); ?>');">
                                            <?php wp_nonce_field('wpclr_demo_action', 'wpclr_nonce'); ?>
                                            <input type="hidden" name="wpclr_admin_action" value="clear_demo_data" />
                                            <button type="submit" class="button button-small" style="color: #b32d2e;">
                                                🗑️ <?php esc_html_e('Clear Demo', 'wpcalibrate-loyalty-rewards'); ?>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php
                        $myAccountRewardsUrl = function_exists('wc_get_account_endpoint_url')
                            ? wc_get_account_endpoint_url('rewards')
                            : home_url('/my-account/rewards/');
                        ?>
                        <a href="<?php echo esc_url($myAccountRewardsUrl); ?>" target="_blank" rel="noopener noreferrer" class="wpclr-step-action">
                            <?php esc_html_e('View My Account Rewards Area', 'wpcalibrate-loyalty-rewards'); ?> &rarr;
                        </a>
                    </div>
                </div>
            </div>

            <!-- Card 3: Under The Hood: Accounting Method & Architecture -->
            <div class="wpclr-card" style="margin-top: 24px;">
                <h3>⚙️ <?php esc_html_e('Under the Hood: Reliable Accounting Methodology', 'wpcalibrate-loyalty-rewards'); ?></h3>
                <p style="color: var(--wpclr-text-muted); margin-bottom: 0;">
                    <?php esc_html_e('WPCalibrate is architected using financial-grade double-entry principles to ensure absolute data integrity, zero race conditions, and complete auditability:', 'wpcalibrate-loyalty-rewards'); ?>
                </p>
                <div class="wpclr-method-grid">
                    <div class="wpclr-method-card">
                        <span class="wpclr-method-icon">📜</span>
                        <h4><?php esc_html_e('Immutable Audit Ledger', 'wpcalibrate-loyalty-rewards'); ?></h4>
                        <p><?php esc_html_e('Every point earned, redeemed, refunded, or expired is appended as an unmodifiable debit or credit row in wp_wpclr_transactions.', 'wpcalibrate-loyalty-rewards'); ?></p>
                    </div>

                    <div class="wpclr-method-card">
                        <span class="wpclr-method-icon">🔒</span>
                        <h4><?php esc_html_e('Idempotent Event Keys', 'wpcalibrate-loyalty-rewards'); ?></h4>
                        <p><?php esc_html_e('Deterministic event keys (e.g. order_123_earn) prevent double points from being awarded even if order webhooks fire concurrently.', 'wpcalibrate-loyalty-rewards'); ?></p>
                    </div>

                    <div class="wpclr-method-card">
                        <span class="wpclr-method-icon">🔄</span>
                        <h4><?php esc_html_e('Reconciliation & Self-Healing', 'wpcalibrate-loyalty-rewards'); ?></h4>
                        <p><?php esc_html_e('User balance metadata is an atomic cache of ledger rows. The Tools tab offers 1-click self-healing reconciliation against the raw transaction ledger.', 'wpcalibrate-loyalty-rewards'); ?></p>
                    </div>

                    <div class="wpclr-method-card">
                        <span class="wpclr-method-icon">⚖️</span>
                        <h4><?php esc_html_e('Proportional Refund Reversal', 'wpcalibrate-loyalty-rewards'); ?></h4>
                        <p><?php esc_html_e('Partial or full refunds automatically reverse the proportional amount of points awarded, protecting stores against return fraud.', 'wpcalibrate-loyalty-rewards'); ?></p>
                    </div>

                    <div class="wpclr-method-card">
                        <span class="wpclr-method-icon">⚡</span>
                        <h4><?php esc_html_e('Async Action Scheduler', 'wpcalibrate-loyalty-rewards'); ?></h4>
                        <p><?php esc_html_e('Points expiration sweeps and reminder dispatches execute safely in WooCommerce Action Scheduler background queues without slowing checkout.', 'wpcalibrate-loyalty-rewards'); ?></p>
                    </div>
                </div>
            </div>

            <!-- Program Status Bar -->
            <div class="wpclr-card" style="margin-top: 24px;">
                <h3>📊 <?php esc_html_e('Program Configuration Highlights', 'wpcalibrate-loyalty-rewards'); ?></h3>
                <div class="wpclr-status-grid">
                    <div>
                        <strong><?php esc_html_e('Base Earning Rate:', 'wpcalibrate-loyalty-rewards'); ?></strong>
                        <p><?php printf(esc_html__('%s point(s) per currency unit spent', 'wpcalibrate-loyalty-rewards'), esc_html((string) SettingsManager::get('points_per_currency', 1.0))); ?></p>
                    </div>
                    <div>
                        <strong><?php esc_html_e('Redemption Ratio:', 'wpcalibrate-loyalty-rewards'); ?></strong>
                        <p><?php printf(esc_html__('%d points = 1.00 store currency', 'wpcalibrate-loyalty-rewards'), (int) SettingsManager::get('redemption_ratio', 100)); ?></p>
                    </div>
                    <div>
                        <strong><?php esc_html_e('Active Campaigns:', 'wpcalibrate-loyalty-rewards'); ?></strong>
                        <p><?php echo esc_html(count($activeCampaigns)); ?> <?php esc_html_e('running', 'wpcalibrate-loyalty-rewards'); ?></p>
                    </div>
                    <div>
                        <strong><?php esc_html_e('Loyalty Tiers:', 'wpcalibrate-loyalty-rewards'); ?></strong>
                        <p><?php echo $tierEnabled ? esc_html__('Active', 'wpcalibrate-loyalty-rewards') : esc_html__('Disabled', 'wpcalibrate-loyalty-rewards'); ?></p>
                    </div>
                </div>
            </div>

            <!-- Recent Ledger Activity -->
            <div class="wpclr-card" style="margin-top: 24px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
                    <h3 style="margin: 0;">📋 <?php esc_html_e('Recent Ledger Activity', 'wpcalibrate-loyalty-rewards'); ?></h3>
                    <a href="<?php echo esc_url(add_query_arg(['page' => AdminMenu::PAGE_SLUG, 'tab' => 'transactions'], admin_url('admin.php'))); ?>" class="button button-secondary">
                        <?php esc_html_e('View Full Ledger', 'wpcalibrate-loyalty-rewards'); ?> &rarr;
                    </a>
                </div>

                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th scope="col"><?php esc_html_e('Date', 'wpcalibrate-loyalty-rewards'); ?></th>
                            <th scope="col"><?php esc_html_e('User ID', 'wpcalibrate-loyalty-rewards'); ?></th>
                            <th scope="col"><?php esc_html_e('Type', 'wpcalibrate-loyalty-rewards'); ?></th>
                            <th scope="col"><?php esc_html_e('Points', 'wpcalibrate-loyalty-rewards'); ?></th>
                            <th scope="col"><?php esc_html_e('Order', 'wpcalibrate-loyalty-rewards'); ?></th>
                            <th scope="col"><?php esc_html_e('Description', 'wpcalibrate-loyalty-rewards'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($recentTransactions)) : ?>
                            <tr><td colspan="6"><?php esc_html_e('No transactions recorded yet.', 'wpcalibrate-loyalty-rewards'); ?></td></tr>
                        <?php else : ?>
                            <?php foreach ($recentTransactions as $tx) : ?>
                                <tr>
                                    <td><?php echo esc_html((string) $tx['created_at']); ?></td>
                                    <td>#<?php echo esc_html((string) $tx['user_id']); ?></td>
                                    <td><code><?php echo esc_html((string) $tx['transaction_type']); ?></code></td>
                                    <td style="font-weight: 600; color: <?php echo (int) $tx['points'] > 0 ? '#007017' : '#b32d2e'; ?>;">
                                        <?php echo (int) $tx['points'] > 0 ? '+' : ''; ?><?php echo esc_html(number_format_i18n((int) $tx['points'])); ?>
                                    </td>
                                    <td><?php echo !empty($tx['order_id']) ? '#' . esc_html((string) $tx['order_id']) : '&mdash;'; ?></td>
                                    <td><?php echo esc_html((string) ($tx['description'] ?? '')); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
    }

    /**
     * Tab: Earning Settings
     */
    private function renderEarningTab(): void
    {
        $settings = SettingsManager::getAll();
        ?>
        <form method="post" action="">
            <?php wp_nonce_field('wpclr_settings_save', 'wpclr_nonce'); ?>
            <input type="hidden" name="wpclr_admin_action" value="save_settings" />

            <div class="wpclr-card">
                <h3><?php esc_html_e('Base Earning Rules', 'wpcalibrate-loyalty-rewards'); ?></h3>
                <p class="description"><?php esc_html_e('Configure how customers earn points from WooCommerce purchases.', 'wpcalibrate-loyalty-rewards'); ?></p>

                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="points_per_currency"><?php esc_html_e('Points per Currency Unit', 'wpcalibrate-loyalty-rewards'); ?></label></th>
                        <td>
                            <input type="number" step="0.01" min="0.01" id="points_per_currency" name="wpclr_settings[points_per_currency]" value="<?php echo esc_attr((string) $settings['points_per_currency']); ?>" class="regular-text" />
                            <p class="description"><?php esc_html_e('Example: 1.0 awards 1 point for every $1 spent.', 'wpcalibrate-loyalty-rewards'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Qualifying Order Statuses', 'wpcalibrate-loyalty-rewards'); ?></th>
                        <td>
                            <?php
                            $availableStatuses = ['completed' => __('Completed', 'wpcalibrate-loyalty-rewards'), 'processing' => __('Processing', 'wpcalibrate-loyalty-rewards'), 'on-hold' => __('On Hold', 'wpcalibrate-loyalty-rewards')];
                            $selectedStatuses = (array) $settings['eligible_order_statuses'];
                            foreach ($availableStatuses as $statusVal => $statusLabel) {
                                $checked = in_array($statusVal, $selectedStatuses, true) ? 'checked' : '';
                                printf(
                                    '<label style="margin-right: 15px;"><input type="checkbox" name="wpclr_settings[eligible_order_statuses][]" value="%s" %s /> %s</label>',
                                    esc_attr($statusVal),
                                    $checked,
                                    esc_html($statusLabel)
                                );
                            }
                            ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="calculation_basis"><?php esc_html_e('Eligible Value Basis', 'wpcalibrate-loyalty-rewards'); ?></label></th>
                        <td>
                            <select id="calculation_basis" name="wpclr_settings[calculation_basis]">
                                <option value="subtotal_ex_tax_shipping" <?php selected($settings['calculation_basis'], 'subtotal_ex_tax_shipping'); ?>>
                                    <?php esc_html_e('Product Subtotal (excluding tax & shipping)', 'wpcalibrate-loyalty-rewards'); ?>
                                </option>
                                <option value="subtotal_inc_tax" <?php selected($settings['calculation_basis'], 'subtotal_inc_tax'); ?>>
                                    <?php esc_html_e('Product Subtotal (including tax, excluding shipping)', 'wpcalibrate-loyalty-rewards'); ?>
                                </option>
                                <option value="total_inc_tax_shipping" <?php selected($settings['calculation_basis'], 'total_inc_tax_shipping'); ?>>
                                    <?php esc_html_e('Total Order Amount (including tax & shipping)', 'wpcalibrate-loyalty-rewards'); ?>
                                </option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Sale Items', 'wpcalibrate-loyalty-rewards'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="wpclr_settings[sale_items_earn]" value="1" <?php checked($settings['sale_items_earn']); ?> />
                                <?php esc_html_e('Allow earning points on discounted / on-sale products', 'wpcalibrate-loyalty-rewards'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="rounding_policy"><?php esc_html_e('Rounding Policy', 'wpcalibrate-loyalty-rewards'); ?></label></th>
                        <td>
                            <select id="rounding_policy" name="wpclr_settings[rounding_policy]">
                                <option value="round" <?php selected($settings['rounding_policy'], 'round'); ?>><?php esc_html_e('Standard Round (Nearest Integer)', 'wpcalibrate-loyalty-rewards'); ?></option>
                                <option value="floor" <?php selected($settings['rounding_policy'], 'floor'); ?>><?php esc_html_e('Floor (Always Round Down)', 'wpcalibrate-loyalty-rewards'); ?></option>
                                <option value="ceil" <?php selected($settings['rounding_policy'], 'ceil'); ?>><?php esc_html_e('Ceil (Always Round Up)', 'wpcalibrate-loyalty-rewards'); ?></option>
                            </select>
                        </td>
                    </tr>
                </table>
            </div>

            <div class="wpclr-card" style="margin-top: 20px;">
                <h3><?php esc_html_e('Action Bonus Rewards', 'wpcalibrate-loyalty-rewards'); ?></h3>

                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e('Account Registration Bonus', 'wpcalibrate-loyalty-rewards'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="wpclr_settings[registration_bonus_enabled]" value="1" <?php checked($settings['registration_bonus_enabled']); ?> />
                                <?php esc_html_e('Award points when a customer creates a new account', 'wpcalibrate-loyalty-rewards'); ?>
                            </label>
                            <br><br>
                            <input type="number" min="1" name="wpclr_settings[registration_bonus_points]" value="<?php echo esc_attr((string) $settings['registration_bonus_points']); ?>" style="width: 100px;" /> <?php esc_html_e('points', 'wpcalibrate-loyalty-rewards'); ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('First Order Bonus', 'wpcalibrate-loyalty-rewards'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="wpclr_settings[first_order_bonus_enabled]" value="1" <?php checked($settings['first_order_bonus_enabled']); ?> />
                                <?php esc_html_e('Award bonus points on the customer\'s very first qualifying purchase', 'wpcalibrate-loyalty-rewards'); ?>
                            </label>
                            <br><br>
                            <input type="number" min="1" name="wpclr_settings[first_order_bonus_points]" value="<?php echo esc_attr((string) $settings['first_order_bonus_points']); ?>" style="width: 100px;" /> <?php esc_html_e('points', 'wpcalibrate-loyalty-rewards'); ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Product Review Bonus', 'wpcalibrate-loyalty-rewards'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="wpclr_settings[review_bonus_enabled]" value="1" <?php checked($settings['review_bonus_enabled']); ?> />
                                <?php esc_html_e('Award bonus points when an authenticated customer submits an approved product review', 'wpcalibrate-loyalty-rewards'); ?>
                            </label>
                            <br><br>
                            <input type="number" min="1" name="wpclr_settings[review_bonus_points]" value="<?php echo esc_attr((string) $settings['review_bonus_points']); ?>" style="width: 100px;" /> <?php esc_html_e('points', 'wpcalibrate-loyalty-rewards'); ?>
                        </td>
                    </tr>
                </table>
            </div>

            <p class="submit" style="margin-top: 20px;">
                <button type="submit" class="button button-primary button-large"><?php esc_html_e('Save Earning Settings', 'wpcalibrate-loyalty-rewards'); ?></button>
            </p>
        </form>
        <?php
    }

    /**
     * Tab: Redemption Settings
     */
    private function renderRedemptionTab(): void
    {
        $settings = SettingsManager::getAll();
        ?>
        <form method="post" action="">
            <?php wp_nonce_field('wpclr_settings_save', 'wpclr_nonce'); ?>
            <input type="hidden" name="wpclr_admin_action" value="save_settings" />

            <div class="wpclr-card">
                <h3><?php esc_html_e('Points-to-Currency Conversion & Thresholds', 'wpcalibrate-loyalty-rewards'); ?></h3>

                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="redemption_ratio"><?php esc_html_e('Redemption Ratio', 'wpcalibrate-loyalty-rewards'); ?></label></th>
                        <td>
                            <input type="number" min="1" id="redemption_ratio" name="wpclr_settings[redemption_ratio]" value="<?php echo esc_attr((string) $settings['redemption_ratio']); ?>" class="small-text" /> <?php esc_html_e('Points = 1.00 store currency', 'wpcalibrate-loyalty-rewards'); ?>
                            <p class="description"><?php esc_html_e('Example: 100 means 100 points give $1.00 discount (each point is worth $0.01).', 'wpcalibrate-loyalty-rewards'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="min_redemption_points"><?php esc_html_e('Minimum Points for Redemption', 'wpcalibrate-loyalty-rewards'); ?></label></th>
                        <td>
                            <input type="number" min="0" id="min_redemption_points" name="wpclr_settings[min_redemption_points]" value="<?php echo esc_attr((string) $settings['min_redemption_points']); ?>" class="small-text" />
                            <p class="description"><?php esc_html_e('Customer cannot redeem unless they enter at least this many points.', 'wpcalibrate-loyalty-rewards'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="max_redemption_points"><?php esc_html_e('Maximum Points per Order', 'wpcalibrate-loyalty-rewards'); ?></label></th>
                        <td>
                            <input type="number" min="0" id="max_redemption_points" name="wpclr_settings[max_redemption_points]" value="<?php echo esc_attr((string) $settings['max_redemption_points']); ?>" class="small-text" />
                            <p class="description"><?php esc_html_e('Set to 0 for no limit.', 'wpcalibrate-loyalty-rewards'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="max_discount_percentage"><?php esc_html_e('Maximum Discount Percentage', 'wpcalibrate-loyalty-rewards'); ?></label></th>
                        <td>
                            <input type="number" min="1" max="100" id="max_discount_percentage" name="wpclr_settings[max_discount_percentage]" value="<?php echo esc_attr((string) $settings['max_discount_percentage']); ?>" class="small-text" /> %
                            <p class="description"><?php esc_html_e('Maximum percentage of eligible cart value payable with points (e.g. 50% or 100%).', 'wpcalibrate-loyalty-rewards'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="min_cart_subtotal"><?php esc_html_e('Minimum Cart Subtotal', 'wpcalibrate-loyalty-rewards'); ?></label></th>
                        <td>
                            <input type="number" step="0.01" min="0" id="min_cart_subtotal" name="wpclr_settings[min_cart_subtotal]" value="<?php echo esc_attr((string) $settings['min_cart_subtotal']); ?>" class="small-text" />
                            <p class="description"><?php esc_html_e('Minimum eligible cart value required before points redemption is allowed.', 'wpcalibrate-loyalty-rewards'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="coupon_compatibility"><?php esc_html_e('Coupon Compatibility', 'wpcalibrate-loyalty-rewards'); ?></label></th>
                        <td>
                            <select id="coupon_compatibility" name="wpclr_settings[coupon_compatibility]">
                                <option value="allow_with_coupons" <?php selected($settings['coupon_compatibility'], 'allow_with_coupons'); ?>>
                                    <?php esc_html_e('Allow points redemption alongside WooCommerce coupons', 'wpcalibrate-loyalty-rewards'); ?>
                                </option>
                                <option value="disallow_with_coupons" <?php selected($settings['coupon_compatibility'], 'disallow_with_coupons'); ?>>
                                    <?php esc_html_e('Disallow points redemption if a coupon is already applied', 'wpcalibrate-loyalty-rewards'); ?>
                                </option>
                            </select>
                        </td>
                    </tr>
                </table>
            </div>

            <p class="submit" style="margin-top: 20px;">
                <button type="submit" class="button button-primary button-large"><?php esc_html_e('Save Redemption Settings', 'wpcalibrate-loyalty-rewards'); ?></button>
            </p>
        </form>
        <?php
    }

    /**
     * Tab: Expiry Settings
     */
    private function renderExpiryTab(): void
    {
        $settings = SettingsManager::getAll();
        ?>
        <form method="post" action="">
            <?php wp_nonce_field('wpclr_settings_save', 'wpclr_nonce'); ?>
            <input type="hidden" name="wpclr_admin_action" value="save_settings" />

            <div class="wpclr-card">
                <h3><?php esc_html_e('Point Expiry Policy', 'wpcalibrate-loyalty-rewards'); ?></h3>
                <p class="description"><?php esc_html_e('When enabled, earned points automatically expire after a configurable validity window using FIFO logic.', 'wpcalibrate-loyalty-rewards'); ?></p>

                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e('Enable Expiry', 'wpcalibrate-loyalty-rewards'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="wpclr_settings[expiry_enabled]" value="1" <?php checked($settings['expiry_enabled']); ?> />
                                <?php esc_html_e('Enable automated loyalty points expiration', 'wpcalibrate-loyalty-rewards'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="expiry_period_days"><?php esc_html_e('Validity Period (Days)', 'wpcalibrate-loyalty-rewards'); ?></label></th>
                        <td>
                            <input type="number" min="1" id="expiry_period_days" name="wpclr_settings[expiry_period_days]" value="<?php echo esc_attr((string) $settings['expiry_period_days']); ?>" class="small-text" /> <?php esc_html_e('days', 'wpcalibrate-loyalty-rewards'); ?>
                            <p class="description"><?php esc_html_e('Points earned will expire this many days after the earning order is completed (e.g. 365 days = 1 year).', 'wpcalibrate-loyalty-rewards'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="expiry_warning_days"><?php esc_html_e('Warning Reminder Lead Time', 'wpcalibrate-loyalty-rewards'); ?></label></th>
                        <td>
                            <input type="number" min="1" id="expiry_warning_days" name="wpclr_settings[expiry_warning_days]" value="<?php echo esc_attr((string) $settings['expiry_warning_days']); ?>" class="small-text" /> <?php esc_html_e('days before expiry', 'wpcalibrate-loyalty-rewards'); ?>
                            <p class="description"><?php esc_html_e('Send an automated warning email this many days before points expire.', 'wpcalibrate-loyalty-rewards'); ?></p>
                        </td>
                    </tr>
                </table>
            </div>

            <p class="submit" style="margin-top: 20px;">
                <button type="submit" class="button button-primary button-large"><?php esc_html_e('Save Expiry Settings', 'wpcalibrate-loyalty-rewards'); ?></button>
            </p>
        </form>
        <?php
    }

    /**
     * Tab: Promotional Campaigns
     */
    private function renderCampaignsTab(): void
    {
        $campaigns = $this->campaignService->getCampaigns();
        ?>
        <div class="wpclr-campaigns-view">
            <div class="wpclr-card">
                <h3><?php esc_html_e('Create Promotional Multiplier Campaign', 'wpcalibrate-loyalty-rewards'); ?></h3>
                <p class="description"><?php esc_html_e('Run double-points weekend or category-specific earning multipliers. When multiple campaigns apply, the highest multiplier wins.', 'wpcalibrate-loyalty-rewards'); ?></p>

                <form method="post" action="" style="margin-top: 15px;">
                    <?php wp_nonce_field('wpclr_campaign_action', 'wpclr_nonce'); ?>
                    <input type="hidden" name="wpclr_admin_action" value="save_campaign" />

                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 15px;">
                        <div>
                            <label for="camp_name"><strong><?php esc_html_e('Campaign Name', 'wpcalibrate-loyalty-rewards'); ?></strong></label>
                            <input type="text" id="camp_name" name="campaign_name" placeholder="e.g. Double Points Weekend" required class="widefat" />
                        </div>
                        <div>
                            <label for="camp_mult"><strong><?php esc_html_e('Multiplier', 'wpcalibrate-loyalty-rewards'); ?></strong></label>
                            <input type="number" step="0.1" min="1.0" id="camp_mult" name="multiplier" value="2.0" class="widefat" />
                        </div>
                        <div>
                            <label for="camp_scope"><strong><?php esc_html_e('Target Scope', 'wpcalibrate-loyalty-rewards'); ?></strong></label>
                            <select id="camp_scope" name="scope" class="widefat">
                                <option value="all"><?php esc_html_e('Global (All Products)', 'wpcalibrate-loyalty-rewards'); ?></option>
                                <option value="products"><?php esc_html_e('Specific Product IDs', 'wpcalibrate-loyalty-rewards'); ?></option>
                                <option value="categories"><?php esc_html_e('Specific Category IDs', 'wpcalibrate-loyalty-rewards'); ?></option>
                            </select>
                        </div>
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-top: 15px;">
                        <div>
                            <label for="start_datetime"><strong><?php esc_html_e('Start Date & Time (Site Local)', 'wpcalibrate-loyalty-rewards'); ?></strong></label>
                            <input type="datetime-local" id="start_datetime" name="start_datetime" class="widefat" />
                        </div>
                        <div>
                            <label for="end_datetime"><strong><?php esc_html_e('End Date & Time (Site Local)', 'wpcalibrate-loyalty-rewards'); ?></strong></label>
                            <input type="datetime-local" id="end_datetime" name="end_datetime" class="widefat" />
                        </div>
                    </div>

                    <div style="margin-top: 15px;">
                        <label>
                            <input type="checkbox" name="enabled" value="1" checked />
                            <strong><?php esc_html_e('Activate campaign immediately', 'wpcalibrate-loyalty-rewards'); ?></strong>
                        </label>
                    </div>

                    <p style="margin-top: 15px;">
                        <button type="submit" class="button button-primary"><?php esc_html_e('Add Campaign', 'wpcalibrate-loyalty-rewards'); ?></button>
                    </p>
                </form>
            </div>

            <!-- Existing Campaigns List -->
            <div class="wpclr-card" style="margin-top: 20px;">
                <h3><?php esc_html_e('Configured Campaigns', 'wpcalibrate-loyalty-rewards'); ?></h3>

                <table class="wp-list-table widefat fixed striped" style="margin-top: 10px;">
                    <thead>
                        <tr>
                            <th scope="col"><?php esc_html_e('Name', 'wpcalibrate-loyalty-rewards'); ?></th>
                            <th scope="col"><?php esc_html_e('Multiplier', 'wpcalibrate-loyalty-rewards'); ?></th>
                            <th scope="col"><?php esc_html_e('Scope', 'wpcalibrate-loyalty-rewards'); ?></th>
                            <th scope="col"><?php esc_html_e('Dates', 'wpcalibrate-loyalty-rewards'); ?></th>
                            <th scope="col"><?php esc_html_e('Status', 'wpcalibrate-loyalty-rewards'); ?></th>
                            <th scope="col" style="width: 100px;"><?php esc_html_e('Action', 'wpcalibrate-loyalty-rewards'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($campaigns)) : ?>
                            <tr><td colspan="6"><?php esc_html_e('No campaigns configured.', 'wpcalibrate-loyalty-rewards'); ?></td></tr>
                        <?php else : ?>
                            <?php foreach ($campaigns as $camp) : ?>
                                <tr>
                                    <td><strong><?php echo esc_html((string) $camp['name']); ?></strong></td>
                                    <td><span class="wpclr-badge-points wpclr-credit"><?php echo esc_html((string) $camp['multiplier']); ?>x</span></td>
                                    <td><code><?php echo esc_html((string) $camp['scope']); ?></code></td>
                                    <td>
                                        <?php echo !empty($camp['start_datetime']) ? esc_html($camp['start_datetime']) : __('Anytime', 'wpcalibrate-loyalty-rewards'); ?>
                                        &rarr;
                                        <?php echo !empty($camp['end_datetime']) ? esc_html($camp['end_datetime']) : __('No End', 'wpcalibrate-loyalty-rewards'); ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($camp['enabled'])) : ?>
                                            <span class="wpclr-status-tag wpclr-status-completed"><?php esc_html_e('Enabled', 'wpcalibrate-loyalty-rewards'); ?></span>
                                        <?php else : ?>
                                            <span class="wpclr-status-tag wpclr-status-cancelled"><?php esc_html_e('Disabled', 'wpcalibrate-loyalty-rewards'); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <form method="post" action="" onsubmit="return confirm('<?php esc_attr_e('Delete this campaign?', 'wpcalibrate-loyalty-rewards'); ?>');">
                                            <?php wp_nonce_field('wpclr_campaign_action', 'wpclr_nonce'); ?>
                                            <input type="hidden" name="wpclr_admin_action" value="delete_campaign" />
                                            <input type="hidden" name="delete_campaign_id" value="<?php echo esc_attr((string) $camp['id']); ?>" />
                                            <button type="submit" class="button button-link-delete"><?php esc_html_e('Delete', 'wpcalibrate-loyalty-rewards'); ?></button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
    }

    /**
     * Tab: Loyalty Tiers
     */
    private function renderTiersTab(): void
    {
        $tierData = $this->tierService->getTierData();
        $tiers = $tierData['list'];
        ?>
        <form method="post" action="">
            <?php wp_nonce_field('wpclr_tiers_action', 'wpclr_nonce'); ?>
            <input type="hidden" name="wpclr_admin_action" value="save_tiers" />

            <div class="wpclr-card">
                <h3><?php esc_html_e('Loyalty Tiers (Lifetime Points)', 'wpcalibrate-loyalty-rewards'); ?></h3>
                <p class="description">
                    <?php esc_html_e('Tiers reward customer loyalty with earning multipliers based on lifetime qualifying earned points. Because tiers use lifetime earned points, customers do not lose tier status when redeeming rewards.', 'wpcalibrate-loyalty-rewards'); ?>
                </p>

                <div style="margin: 15px 0;">
                    <label>
                        <input type="checkbox" name="tiers_enabled" value="1" <?php checked($tierData['enabled']); ?> />
                        <strong><?php esc_html_e('Enable Loyalty Tiers (Disabled by default)', 'wpcalibrate-loyalty-rewards'); ?></strong>
                    </label>
                </div>

                <table class="wp-list-table widefat fixed striped" style="margin-top: 15px;">
                    <thead>
                        <tr>
                            <th scope="col"><?php esc_html_e('Tier Name', 'wpcalibrate-loyalty-rewards'); ?></th>
                            <th scope="col"><?php esc_html_e('Lifetime Points Threshold', 'wpcalibrate-loyalty-rewards'); ?></th>
                            <th scope="col"><?php esc_html_e('Earning Multiplier', 'wpcalibrate-loyalty-rewards'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php for ($i = 0; $i < 4; $i++) : ?>
                            <?php $tier = $tiers[$i] ?? ['name' => '', 'points' => 0, 'multiplier' => 1.0]; ?>
                            <tr>
                                <td>
                                    <input type="text" name="tiers[<?php echo $i; ?>][name]" value="<?php echo esc_attr((string) $tier['name']); ?>" placeholder="<?php esc_attr_e('Tier Name', 'wpcalibrate-loyalty-rewards'); ?>" class="regular-text" />
                                </td>
                                <td>
                                    <input type="number" min="0" name="tiers[<?php echo $i; ?>][points]" value="<?php echo esc_attr((string) ($tier['points'] ?? 0)); ?>" class="small-text" /> <?php esc_html_e('pts', 'wpcalibrate-loyalty-rewards'); ?>
                                </td>
                                <td>
                                    <input type="number" step="0.05" min="1.0" name="tiers[<?php echo $i; ?>][multiplier]" value="<?php echo esc_attr((string) ($tier['multiplier'] ?? 1.0)); ?>" class="small-text" /> x
                                </td>
                            </tr>
                        <?php endfor; ?>
                    </tbody>
                </table>

                <p class="submit" style="margin-top: 20px;">
                    <button type="submit" class="button button-primary button-large"><?php esc_html_e('Save Loyalty Tiers', 'wpcalibrate-loyalty-rewards'); ?></button>
                </p>
            </div>
        </form>
        <?php
    }

    /**
     * Tab: Customers
     */
    private function renderCustomersTab(): void
    {
        $search = sanitize_text_field($_GET['s'] ?? '');
        $page = max(1, absint($_GET['paged'] ?? 1));
        $perPage = 20;
        $offset = ($page - 1) * $perPage;

        $customerData = $this->customerController->getCustomers($search, $perPage, $offset);
        $totalUsers = $customerData['total'];
        $totalPages = max(1, (int) ceil($totalUsers / $perPage));
        $users = $customerData['users'];

        ?>
        <div class="wpclr-customers-view">
            <!-- Search & Adjustment Card -->
            <div class="wpclr-card">
                <h3><?php esc_html_e('Manual Customer Points Adjustment', 'wpcalibrate-loyalty-rewards'); ?></h3>
                <p class="description"><?php esc_html_e('Credit or debit points for any customer with an explicit audit reason and acting administrator recording.', 'wpcalibrate-loyalty-rewards'); ?></p>

                <form method="post" action="" style="margin-top: 15px; display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 15px;">
                    <?php wp_nonce_field('wpclr_customer_action', 'wpclr_nonce'); ?>
                    <input type="hidden" name="wpclr_action" value="adjust_points" />

                    <div>
                        <label for="adj_user_id"><strong><?php esc_html_e('Customer User ID', 'wpcalibrate-loyalty-rewards'); ?></strong></label>
                        <input type="number" min="1" id="adj_user_id" name="user_id" required class="widefat" />
                    </div>

                    <div>
                        <label for="adj_points"><strong><?php esc_html_e('Points Delta (+/-)', 'wpcalibrate-loyalty-rewards'); ?></strong></label>
                        <input type="number" id="adj_points" name="points" placeholder="e.g. 100 or -50" required class="widefat" />
                    </div>

                    <div>
                        <label for="adj_order_id"><strong><?php esc_html_e('Related Order ID (Optional)', 'wpcalibrate-loyalty-rewards'); ?></strong></label>
                        <input type="number" id="adj_order_id" name="order_id" placeholder="Optional" class="widefat" />
                    </div>

                    <div style="grid-column: 1 / -1;">
                        <label for="adj_reason"><strong><?php esc_html_e('Audit Reason / Internal Note', 'wpcalibrate-loyalty-rewards'); ?></strong></label>
                        <input type="text" id="adj_reason" name="reason" placeholder="<?php esc_attr_e('Mandatory audit explanation (e.g. VIP goodwill, dispute resolution)', 'wpcalibrate-loyalty-rewards'); ?>" required class="widefat" />
                    </div>

                    <div style="grid-column: 1 / -1;">
                        <button type="submit" class="button button-primary" onclick="return confirm('<?php esc_attr_e('Confirm point adjustment for this customer?', 'wpcalibrate-loyalty-rewards'); ?>');">
                            <?php esc_html_e('Apply Points Adjustment', 'wpcalibrate-loyalty-rewards'); ?>
                        </button>
                    </div>
                </form>
            </div>

            <!-- Customer Search Table -->
            <div class="wpclr-card" style="margin-top: 20px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                    <h3 style="margin: 0;"><?php esc_html_e('Registered Customers & Balances', 'wpcalibrate-loyalty-rewards'); ?></h3>
                    <form method="get" action="">
                        <input type="hidden" name="page" value="<?php echo esc_attr(AdminMenu::PAGE_SLUG); ?>" />
                        <input type="hidden" name="tab" value="customers" />
                        <input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="<?php esc_attr_e('Search by name or email...', 'wpcalibrate-loyalty-rewards'); ?>" />
                        <button type="submit" class="button"><?php esc_html_e('Search', 'wpcalibrate-loyalty-rewards'); ?></button>
                    </form>
                </div>

                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th scope="col" style="width: 70px;"><?php esc_html_e('ID', 'wpcalibrate-loyalty-rewards'); ?></th>
                            <th scope="col"><?php esc_html_e('Name', 'wpcalibrate-loyalty-rewards'); ?></th>
                            <th scope="col"><?php esc_html_e('Email', 'wpcalibrate-loyalty-rewards'); ?></th>
                            <th scope="col"><?php esc_html_e('Available Balance', 'wpcalibrate-loyalty-rewards'); ?></th>
                            <th scope="col"><?php esc_html_e('Lifetime Earned', 'wpcalibrate-loyalty-rewards'); ?></th>
                            <th scope="col"><?php esc_html_e('Lifetime Redeemed', 'wpcalibrate-loyalty-rewards'); ?></th>
                            <th scope="col" style="width: 140px;"><?php esc_html_e('Reconcile', 'wpcalibrate-loyalty-rewards'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($users)) : ?>
                            <tr><td colspan="7"><?php esc_html_e('No customers found.', 'wpcalibrate-loyalty-rewards'); ?></td></tr>
                        <?php else : ?>
                            <?php foreach ($users as $u) : ?>
                                <tr>
                                    <td>#<?php echo esc_html((string) $u['id']); ?></td>
                                    <td><strong><?php echo esc_html($u['name']); ?></strong></td>
                                    <td><?php echo esc_html($u['email']); ?></td>
                                    <td><span class="wpclr-badge-points wpclr-credit"><?php echo esc_html(number_format_i18n($u['balance'])); ?> pts</span></td>
                                    <td>+<?php echo esc_html(number_format_i18n($u['lifetime_in'])); ?></td>
                                    <td>-<?php echo esc_html(number_format_i18n($u['lifetime_out'])); ?></td>
                                    <td>
                                        <form method="post" action="" style="display:inline;">
                                            <?php wp_nonce_field('wpclr_customer_action', 'wpclr_nonce'); ?>
                                            <input type="hidden" name="wpclr_action" value="reconcile_balance" />
                                            <input type="hidden" name="user_id" value="<?php echo esc_attr((string) $u['id']); ?>" />
                                            <button type="submit" class="button button-small" title="<?php esc_attr_e('Recalculate balance from ledger', 'wpcalibrate-loyalty-rewards'); ?>">
                                                🔄 <?php esc_html_e('Reconcile', 'wpcalibrate-loyalty-rewards'); ?>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>

                <?php if ($totalPages > 1) : ?>
                    <div class="tablenav" style="margin-top: 15px;">
                        <div class="tablenav-pages">
                            <span class="displaying-num"><?php printf(esc_html__('%d customers', 'wpcalibrate-loyalty-rewards'), $totalUsers); ?></span>
                            <?php
                            echo paginate_links([
                                'base'      => add_query_arg('paged', '%#%'),
                                'format'    => '',
                                'prev_text' => '&laquo;',
                                'next_text' => '&raquo;',
                                'total'     => $totalPages,
                                'current'   => $page,
                            ]);
                            ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /**
     * Tab: Transactions
     */
    private function renderTransactionsTab(): void
    {
        $data = $this->transactionController->getPaginatedTransactions(25);
        $rows = $data['rows'];
        $total = $data['total'];
        $totalPages = $data['total_pages'];
        $currentPage = $data['current_page'];

        $exportUrl = wp_nonce_url(
            add_query_arg(
                array_merge($_GET, ['wpclr_action' => 'export_transactions']),
                admin_url('admin.php')
            ),
            'wpclr_export_csv',
            'wpclr_nonce'
        );

        ?>
        <div class="wpclr-transactions-view">
            <!-- Filter Bar -->
            <div class="wpclr-card">
                <form method="get" action="" style="display: flex; flex-wrap: wrap; gap: 10px; align-items: flex-end;">
                    <input type="hidden" name="page" value="<?php echo esc_attr(AdminMenu::PAGE_SLUG); ?>" />
                    <input type="hidden" name="tab" value="transactions" />

                    <div>
                        <label for="filter_user"><strong><?php esc_html_e('User ID', 'wpcalibrate-loyalty-rewards'); ?></strong></label><br>
                        <input type="number" id="filter_user" name="user_id" value="<?php echo esc_attr($_GET['user_id'] ?? ''); ?>" style="width: 100px;" />
                    </div>

                    <div>
                        <label for="filter_type"><strong><?php esc_html_e('Type', 'wpcalibrate-loyalty-rewards'); ?></strong></label><br>
                        <select id="filter_type" name="transaction_type">
                            <option value=""><?php esc_html_e('All Types', 'wpcalibrate-loyalty-rewards'); ?></option>
                            <option value="earn_order" <?php selected($_GET['transaction_type'] ?? '', 'earn_order'); ?>>earn_order</option>
                            <option value="redeem" <?php selected($_GET['transaction_type'] ?? '', 'redeem'); ?>>redeem</option>
                            <option value="refund_reversal" <?php selected($_GET['transaction_type'] ?? '', 'refund_reversal'); ?>>refund_reversal</option>
                            <option value="expiry" <?php selected($_GET['transaction_type'] ?? '', 'expiry'); ?>>expiry</option>
                            <option value="admin_credit" <?php selected($_GET['transaction_type'] ?? '', 'admin_credit'); ?>>admin_credit</option>
                            <option value="admin_debit" <?php selected($_GET['transaction_type'] ?? '', 'admin_debit'); ?>>admin_debit</option>
                        </select>
                    </div>

                    <div>
                        <label for="filter_date_from"><strong><?php esc_html_e('From Date', 'wpcalibrate-loyalty-rewards'); ?></strong></label><br>
                        <input type="date" id="filter_date_from" name="date_from" value="<?php echo esc_attr($_GET['date_from'] ?? ''); ?>" />
                    </div>

                    <div>
                        <label for="filter_date_to"><strong><?php esc_html_e('To Date', 'wpcalibrate-loyalty-rewards'); ?></strong></label><br>
                        <input type="date" id="filter_date_to" name="date_to" value="<?php echo esc_attr($_GET['date_to'] ?? ''); ?>" />
                    </div>

                    <div>
                        <button type="submit" class="button button-primary"><?php esc_html_e('Filter Ledger', 'wpcalibrate-loyalty-rewards'); ?></button>
                        <a href="<?php echo esc_url(add_query_arg(['page' => AdminMenu::PAGE_SLUG, 'tab' => 'transactions'], admin_url('admin.php'))); ?>" class="button">
                            <?php esc_html_e('Reset', 'wpcalibrate-loyalty-rewards'); ?>
                        </a>
                        <a href="<?php echo esc_url($exportUrl); ?>" class="button button-secondary">
                            📥 <?php esc_html_e('Export Filtered CSV', 'wpcalibrate-loyalty-rewards'); ?>
                        </a>
                    </div>
                </form>
            </div>

            <!-- Ledger Table -->
            <div class="wpclr-card" style="margin-top: 20px;">
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th scope="col" style="width: 60px;"><?php esc_html_e('ID', 'wpcalibrate-loyalty-rewards'); ?></th>
                            <th scope="col" style="width: 150px;"><?php esc_html_e('Date (UTC)', 'wpcalibrate-loyalty-rewards'); ?></th>
                            <th scope="col" style="width: 80px;"><?php esc_html_e('User ID', 'wpcalibrate-loyalty-rewards'); ?></th>
                            <th scope="col" style="width: 130px;"><?php esc_html_e('Type', 'wpcalibrate-loyalty-rewards'); ?></th>
                            <th scope="col" style="width: 90px;"><?php esc_html_e('Points', 'wpcalibrate-loyalty-rewards'); ?></th>
                            <th scope="col" style="width: 90px;"><?php esc_html_e('Order ID', 'wpcalibrate-loyalty-rewards'); ?></th>
                            <th scope="col"><?php esc_html_e('Description / Audit Reason', 'wpcalibrate-loyalty-rewards'); ?></th>
                            <th scope="col" style="width: 90px;"><?php esc_html_e('Status', 'wpcalibrate-loyalty-rewards'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($rows)) : ?>
                            <tr><td colspan="8"><?php esc_html_e('No transactions matched the criteria.', 'wpcalibrate-loyalty-rewards'); ?></td></tr>
                        <?php else : ?>
                            <?php foreach ($rows as $tx) : ?>
                                <tr>
                                    <td>#<?php echo esc_html((string) $tx['id']); ?></td>
                                    <td><?php echo esc_html((string) $tx['created_at']); ?></td>
                                    <td><a href="<?php echo esc_url(add_query_arg(['page' => AdminMenu::PAGE_SLUG, 'tab' => 'customers', 's' => $tx['user_id']], admin_url('admin.php'))); ?>">#<?php echo esc_html((string) $tx['user_id']); ?></a></td>
                                    <td><code><?php echo esc_html((string) $tx['transaction_type']); ?></code></td>
                                    <td style="font-weight: 600; color: <?php echo (int) $tx['points'] > 0 ? '#007017' : '#b32d2e'; ?>;">
                                        <?php echo (int) $tx['points'] > 0 ? '+' : ''; ?><?php echo esc_html(number_format_i18n((int) $tx['points'])); ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($tx['order_id'])) : ?>
                                            <a href="<?php echo esc_url(admin_url('post.php?post=' . $tx['order_id'] . '&action=edit')); ?>">
                                                #<?php echo esc_html((string) $tx['order_id']); ?>
                                            </a>
                                        <?php else : ?>
                                            &mdash;
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo esc_html((string) ($tx['description'] ?? '')); ?></td>
                                    <td>
                                        <span class="wpclr-status-tag wpclr-status-<?php echo esc_attr(sanitize_html_class((string) $tx['status'])); ?>">
                                            <?php echo esc_html((string) $tx['status']); ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>

                <?php if ($totalPages > 1) : ?>
                    <div class="tablenav" style="margin-top: 15px;">
                        <div class="tablenav-pages">
                            <span class="displaying-num"><?php printf(esc_html__('%d transactions', 'wpcalibrate-loyalty-rewards'), $total); ?></span>
                            <?php
                            echo paginate_links([
                                'base'      => add_query_arg('paged', '%#%'),
                                'format'    => '',
                                'prev_text' => '&laquo;',
                                'next_text' => '&raquo;',
                                'total'     => $totalPages,
                                'current'   => $currentPage,
                            ]);
                            ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /**
     * Tab: Emails
     */
    private function renderEmailsTab(): void
    {
        $settings = SettingsManager::getAll();
        ?>
        <form method="post" action="">
            <?php wp_nonce_field('wpclr_settings_save', 'wpclr_nonce'); ?>
            <input type="hidden" name="wpclr_admin_action" value="save_settings" />

            <div class="wpclr-card">
                <h3><?php esc_html_e('Customer Transactional Email Notifications', 'wpcalibrate-loyalty-rewards'); ?></h3>
                <p class="description"><?php esc_html_e('Control automated WooCommerce emails sent to customers regarding points updates.', 'wpcalibrate-loyalty-rewards'); ?></p>

                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e('Points Earned Notification', 'wpcalibrate-loyalty-rewards'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="wpclr_settings[email_points_earned_enabled]" value="1" <?php checked($settings['email_points_earned_enabled']); ?> />
                                <?php esc_html_e('Send email when customer earns points from a completed purchase or bonus event', 'wpcalibrate-loyalty-rewards'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Points Redeemed Confirmation', 'wpcalibrate-loyalty-rewards'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="wpclr_settings[email_points_redeemed_enabled]" value="1" <?php checked($settings['email_points_redeemed_enabled']); ?> />
                                <?php esc_html_e('Send email confirming points redeemed for an order discount', 'wpcalibrate-loyalty-rewards'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Points Adjusted Notification', 'wpcalibrate-loyalty-rewards'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="wpclr_settings[email_points_adjusted_enabled]" value="1" <?php checked($settings['email_points_adjusted_enabled']); ?> />
                                <?php esc_html_e('Send email when an administrator manually credits or debits points', 'wpcalibrate-loyalty-rewards'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Points Expiring Warning Notice', 'wpcalibrate-loyalty-rewards'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="wpclr_settings[email_points_expiring_enabled]" value="1" <?php checked($settings['email_points_expiring_enabled']); ?> />
                                <?php esc_html_e('Send warning email to customers prior to point expiration', 'wpcalibrate-loyalty-rewards'); ?>
                            </label>
                        </td>
                    </tr>
                </table>
            </div>

            <p class="submit" style="margin-top: 20px;">
                <button type="submit" class="button button-primary button-large"><?php esc_html_e('Save Email Settings', 'wpcalibrate-loyalty-rewards'); ?></button>
            </p>
        </form>
        <?php
    }

    /**
     * Tab: Reports
     */
    private function renderReportsTab(): void
    {
        $dateFrom = sanitize_text_field($_GET['date_from'] ?? '');
        $dateTo = sanitize_text_field($_GET['date_to'] ?? '');

        $stats = $this->reportService->getSummaryStats($dateFrom ?: null, $dateTo ?: null);
        ?>
        <div class="wpclr-reports-view">
            <div class="wpclr-card">
                <form method="get" action="" style="display: flex; gap: 15px; align-items: flex-end;">
                    <input type="hidden" name="page" value="<?php echo esc_attr(AdminMenu::PAGE_SLUG); ?>" />
                    <input type="hidden" name="tab" value="reports" />

                    <div>
                        <label for="rep_from"><strong><?php esc_html_e('Start Date', 'wpcalibrate-loyalty-rewards'); ?></strong></label><br>
                        <input type="date" id="rep_from" name="date_from" value="<?php echo esc_attr($dateFrom); ?>" />
                    </div>

                    <div>
                        <label for="rep_to"><strong><?php esc_html_e('End Date', 'wpcalibrate-loyalty-rewards'); ?></strong></label><br>
                        <input type="date" id="rep_to" name="date_to" value="<?php echo esc_attr($dateTo); ?>" />
                    </div>

                    <div>
                        <button type="submit" class="button button-primary"><?php esc_html_e('Filter Report', 'wpcalibrate-loyalty-rewards'); ?></button>
                    </div>
                </form>
            </div>

            <div class="wpclr-kpi-grid" style="margin-top: 20px;">
                <div class="wpclr-kpi-card">
                    <span class="wpclr-kpi-icon">🎯</span>
                    <div class="wpclr-kpi-data">
                        <div class="wpclr-kpi-title"><?php esc_html_e('Points Issued in Period', 'wpcalibrate-loyalty-rewards'); ?></div>
                        <div class="wpclr-kpi-val text-success"><?php echo esc_html(number_format_i18n($stats['total_issued'])); ?></div>
                    </div>
                </div>

                <div class="wpclr-kpi-card">
                    <span class="wpclr-kpi-icon">💳</span>
                    <div class="wpclr-kpi-data">
                        <div class="wpclr-kpi-title"><?php esc_html_e('Points Redeemed in Period', 'wpcalibrate-loyalty-rewards'); ?></div>
                        <div class="wpclr-kpi-val text-warning"><?php echo esc_html(number_format_i18n($stats['total_redeemed'])); ?></div>
                    </div>
                </div>

                <div class="wpclr-kpi-card">
                    <span class="wpclr-kpi-icon">⏳</span>
                    <div class="wpclr-kpi-data">
                        <div class="wpclr-kpi-title"><?php esc_html_e('Points Expired in Period', 'wpcalibrate-loyalty-rewards'); ?></div>
                        <div class="wpclr-kpi-val text-danger"><?php echo esc_html(number_format_i18n($stats['total_expired'])); ?></div>
                    </div>
                </div>

                <div class="wpclr-kpi-card">
                    <span class="wpclr-kpi-icon">👥</span>
                    <div class="wpclr-kpi-data">
                        <div class="wpclr-kpi-title"><?php esc_html_e('Participating Customers', 'wpcalibrate-loyalty-rewards'); ?></div>
                        <div class="wpclr-kpi-val text-primary"><?php echo esc_html(number_format_i18n($stats['participating_customers'])); ?></div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Tab: Tools
     */
    private function renderToolsTab(): void
    {
        $diagnostics = SupportInfo::getSafeDiagnostics();
        $currentUserId = get_current_user_id();
        $hasDemo = $this->demoDataService->hasDemoData($currentUserId);
        $myAccountRewardsUrl = function_exists('wc_get_account_endpoint_url')
            ? wc_get_account_endpoint_url('rewards')
            : home_url('/my-account/rewards/');
        ?>
        <div class="wpclr-tools-view">
            <!-- Demo Data & Showcase Card -->
            <div class="wpclr-card">
                <h3>🚀 <?php esc_html_e('Demo Data & Storefront Showcase Generator', 'wpcalibrate-loyalty-rewards'); ?></h3>
                <p class="description">
                    <?php esc_html_e('Populate realistic sample loyalty activity (Welcome bonus, Order earnings, Approved Review bonus, Double Points campaign multiplier, and a Cart redemption discount) to immediately test and experience how the plugin appears and functions across your website.', 'wpcalibrate-loyalty-rewards'); ?>
                </p>

                <div style="display: flex; gap: 12px; align-items: center; margin-top: 16px; flex-wrap: wrap;">
                    <form method="post" action="">
                        <?php wp_nonce_field('wpclr_demo_action', 'wpclr_nonce'); ?>
                        <input type="hidden" name="wpclr_admin_action" value="generate_demo_data" />
                        <input type="hidden" name="demo_user_id" value="<?php echo esc_attr((string) $currentUserId); ?>" />
                        <button type="submit" class="button button-primary">
                            ✨ <?php esc_html_e('Generate Demo Data for Current Account', 'wpcalibrate-loyalty-rewards'); ?>
                        </button>
                    </form>

                    <?php if ($hasDemo) : ?>
                        <form method="post" action="" onsubmit="return confirm('<?php echo esc_js(__('Are you sure you want to clear demo transactions for this account?', 'wpcalibrate-loyalty-rewards')); ?>');">
                            <?php wp_nonce_field('wpclr_demo_action', 'wpclr_nonce'); ?>
                            <input type="hidden" name="wpclr_admin_action" value="clear_demo_data" />
                            <input type="hidden" name="demo_user_id" value="<?php echo esc_attr((string) $currentUserId); ?>" />
                            <button type="submit" class="button button-link-delete" style="color: #b32d2e;">
                                🗑️ <?php esc_html_e('Clear Demo Data', 'wpcalibrate-loyalty-rewards'); ?>
                            </button>
                        </form>
                    <?php endif; ?>

                    <a href="<?php echo esc_url($myAccountRewardsUrl); ?>" target="_blank" rel="noopener noreferrer" class="button button-secondary">
                        🌐 <?php esc_html_e('Open My Account Rewards in Storefront', 'wpcalibrate-loyalty-rewards'); ?> &rarr;
                    </a>
                </div>
            </div>

            <div class="wpclr-card" style="margin-top: 20px;">
                <h3><?php esc_html_e('Loyalty Maintenance Utilities', 'wpcalibrate-loyalty-rewards'); ?></h3>

                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e('Process Expiration Batch Now', 'wpcalibrate-loyalty-rewards'); ?></th>
                        <td>
                            <form method="post" action="">
                                <?php wp_nonce_field('wpclr_tools_action', 'wpclr_nonce'); ?>
                                <input type="hidden" name="wpclr_admin_action" value="process_expiry_now" />
                                <button type="submit" class="button"><?php esc_html_e('Run Expiry Job Now', 'wpcalibrate-loyalty-rewards'); ?></button>
                                <p class="description"><?php esc_html_e('Manually triggers an immediate batch of point expirations using Action Scheduler logic.', 'wpcalibrate-loyalty-rewards'); ?></p>
                            </form>
                        </td>
                    </tr>
                </table>
            </div>

            <!-- Diagnostic Information -->
            <div class="wpclr-card" style="margin-top: 20px;">
                <h3><?php esc_html_e('System Diagnostics (Safe for Support)', 'wpcalibrate-loyalty-rewards'); ?></h3>
                <p class="description"><?php esc_html_e('Diagnostic information for technical support. Never contains passwords, plain keys, or sensitive customer data.', 'wpcalibrate-loyalty-rewards'); ?></p>

                <textarea readonly rows="9" class="large-text code" style="margin-top: 10px;"><?php echo esc_textarea(wp_json_encode($diagnostics, JSON_PRETTY_PRINT)); ?></textarea>
            </div>
        </div>
        <?php
    }

    /**
     * Tab: License
     */
    private function renderLicenseTab(): void
    {
        $status = $this->licenseService->getStatus();
        $maskedKey = $this->licenseService->getMaskedKey();
        ?>
        <div class="wpclr-license-view">
            <div class="wpclr-card">
                <h3><?php esc_html_e('WPCalibrate Marketplace License', 'wpcalibrate-loyalty-rewards'); ?></h3>
                <p class="description"><?php esc_html_e('Manage your WPCalibrate product license and automated updates.', 'wpcalibrate-loyalty-rewards'); ?></p>

                <div class="notice notice-info inline" style="margin: 15px 0;">
                    <p>
                        <strong><?php esc_html_e('Licensing Provider Status:', 'wpcalibrate-loyalty-rewards'); ?></strong>
                        <?php esc_html_e('A dedicated external licensing provider endpoint has not yet been configured for this environment. Validation requires an active WPCalibrate Marketplace provider adapter.', 'wpcalibrate-loyalty-rewards'); ?>
                    </p>
                </div>

                <form method="post" action="" style="margin-top: 20px;">
                    <?php wp_nonce_field('wpclr_license_action', 'wpclr_nonce'); ?>
                    <input type="hidden" name="wpclr_admin_action" value="activate_license" />

                    <table class="form-table">
                        <tr>
                            <th scope="row"><label for="license_key"><?php esc_html_e('License Key', 'wpcalibrate-loyalty-rewards'); ?></label></th>
                            <td>
                                <input type="password" id="license_key" name="license_key" value="<?php echo esc_attr($maskedKey); ?>" class="regular-text" placeholder="WPCLR-XXXX-XXXX-XXXX" />
                                <p class="description"><?php esc_html_e('Enter your purchase license key from marketplace.wpcalibrate.com.', 'wpcalibrate-loyalty-rewards'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Status', 'wpcalibrate-loyalty-rewards'); ?></th>
                            <td>
                                <span class="wpclr-status-tag wpclr-status-<?php echo esc_attr(sanitize_html_class($status)); ?>">
                                    <?php echo esc_html(ucfirst($status)); ?>
                                </span>
                            </td>
                        </tr>
                    </table>

                    <p class="submit">
                        <button type="submit" class="button button-primary"><?php esc_html_e('Activate License', 'wpcalibrate-loyalty-rewards'); ?></button>
                    </p>
                </form>

                <?php if ($status === 'active') : ?>
                    <form method="post" action="" style="margin-top: 10px;">
                        <?php wp_nonce_field('wpclr_license_action', 'wpclr_nonce'); ?>
                        <input type="hidden" name="wpclr_admin_action" value="deactivate_license" />
                        <button type="submit" class="button button-secondary"><?php esc_html_e('Deactivate License', 'wpcalibrate-loyalty-rewards'); ?></button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /**
     * Tab: Advanced
     */
    private function renderAdvancedTab(): void
    {
        $settings = SettingsManager::getAll();
        ?>
        <form method="post" action="">
            <?php wp_nonce_field('wpclr_settings_save', 'wpclr_nonce'); ?>
            <input type="hidden" name="wpclr_admin_action" value="save_settings" />

            <div class="wpclr-card">
                <h3><?php esc_html_e('Presentation & Labels', 'wpcalibrate-loyalty-rewards'); ?></h3>

                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="point_label_singular"><?php esc_html_e('Point Label (Singular)', 'wpcalibrate-loyalty-rewards'); ?></label></th>
                        <td>
                            <input type="text" id="point_label_singular" name="wpclr_settings[point_label_singular]" value="<?php echo esc_attr((string) $settings['point_label_singular']); ?>" class="regular-text" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="point_label_plural"><?php esc_html_e('Point Label (Plural)', 'wpcalibrate-loyalty-rewards'); ?></label></th>
                        <td>
                            <input type="text" id="point_label_plural" name="wpclr_settings[point_label_plural]" value="<?php echo esc_attr((string) $settings['point_label_plural']); ?>" class="regular-text" />
                        </td>
                    </tr>
                </table>
            </div>

            <div class="wpclr-card" style="margin-top: 20px;">
                <h3><?php esc_html_e('Data Deletion & Uninstall', 'wpcalibrate-loyalty-rewards'); ?></h3>

                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e('Delete Data on Uninstall', 'wpcalibrate-loyalty-rewards'); ?></th>
                        <td>
                            <label style="color: #b32d2e;">
                                <input type="checkbox" name="wpclr_settings[delete_on_uninstall]" value="1" <?php checked($settings['delete_on_uninstall']); ?> />
                                <strong><?php esc_html_e('Delete all WPCalibrate Loyalty & Rewards data on uninstall', 'wpcalibrate-loyalty-rewards'); ?></strong>
                            </label>
                            <p class="description"><?php esc_html_e('WARNING: When enabled, deleting this plugin from WordPress will permanently drop the rewards ledger table and erase all customer points. Disabled by default.', 'wpcalibrate-loyalty-rewards'); ?></p>
                        </td>
                    </tr>
                </table>
            </div>

            <p class="submit" style="margin-top: 20px;">
                <button type="submit" class="button button-primary button-large"><?php esc_html_e('Save Advanced Settings', 'wpcalibrate-loyalty-rewards'); ?></button>
            </p>
        </form>
        <?php
    }

    /**
     * Tab: Support
     */
    private function renderSupportTab(): void
    {
        SupportInfo::renderSupportCard();
    }
}
