<?php
declare(strict_types=1);

namespace WPCalibrate\LoyaltyRewards;

use WPCalibrate\LoyaltyRewards\Admin\AdminMenu;
use WPCalibrate\LoyaltyRewards\Admin\CustomerAdminController;
use WPCalibrate\LoyaltyRewards\Admin\SettingsPage;
use WPCalibrate\LoyaltyRewards\Admin\TransactionAdminController;
use WPCalibrate\LoyaltyRewards\Blocks\BlocksIntegration;
use WPCalibrate\LoyaltyRewards\Campaigns\CampaignService;
use WPCalibrate\LoyaltyRewards\Customers\FirstOrderRewardService;
use WPCalibrate\LoyaltyRewards\Customers\MyAccountRewardsArea;
use WPCalibrate\LoyaltyRewards\Customers\RegistrationRewardService;
use WPCalibrate\LoyaltyRewards\Customers\ReviewRewardService;
use WPCalibrate\LoyaltyRewards\Database\MigrationManager;
use WPCalibrate\LoyaltyRewards\Database\TransactionRepository;
use WPCalibrate\LoyaltyRewards\Emails\EmailManager;
use WPCalibrate\LoyaltyRewards\Expiration\ExpirationService;
use WPCalibrate\LoyaltyRewards\Licensing\LicenseService;
use WPCalibrate\LoyaltyRewards\Orders\OrderAdminIntegration;
use WPCalibrate\LoyaltyRewards\Orders\OrderRewardService;
use WPCalibrate\LoyaltyRewards\Orders\RefundReversalService;
use WPCalibrate\LoyaltyRewards\Points\BalanceService;
use WPCalibrate\LoyaltyRewards\Points\CalculationEngine;
use WPCalibrate\LoyaltyRewards\Points\TransactionService;
use WPCalibrate\LoyaltyRewards\Redemption\ClassicCartRedemption;
use WPCalibrate\LoyaltyRewards\Redemption\RedemptionService;
use WPCalibrate\LoyaltyRewards\Reports\ReportService;
use WPCalibrate\LoyaltyRewards\Support\PrivacyManager;
use WPCalibrate\LoyaltyRewards\Tiers\TierService;
use WPCalibrate\LoyaltyRewards\Updates\GitHubUpdater;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Main application coordinator and dependency container for WPCalibrate Loyalty & Rewards.
 */
final class Plugin
{
    private static ?self $instance = null;

    // Services
    private TransactionRepository $repository;
    private BalanceService $balanceService;
    private TransactionService $transactionService;
    private CampaignService $campaignService;
    private TierService $tierService;
    private CalculationEngine $calculationEngine;
    private OrderRewardService $orderRewardService;
    private RegistrationRewardService $registrationRewardService;
    private ReviewRewardService $reviewRewardService;
    private RedemptionService $redemptionService;
    private ClassicCartRedemption $classicCartRedemption;
    private BlocksIntegration $blocksIntegration;
    private OrderAdminIntegration $orderAdminIntegration;
    private RefundReversalService $refundReversalService;
    private ExpirationService $expirationService;
    private MyAccountRewardsArea $myAccountRewardsArea;
    private CustomerAdminController $customerAdminController;
    private TransactionAdminController $transactionAdminController;
    private ReportService $reportService;
    private EmailManager $emailManager;
    private LicenseService $licenseService;
    private PrivacyManager $privacyManager;
    private SettingsPage $settingsPage;
    private AdminMenu $adminMenu;
    private GitHubUpdater $gitHubUpdater;

    private function __construct()
    {
        // Compose core repositories and services
        $this->repository = new TransactionRepository();
        $this->balanceService = new BalanceService($this->repository);
        $this->transactionService = new TransactionService($this->repository, $this->balanceService);
        $this->campaignService = new CampaignService();
        $this->tierService = new TierService($this->repository);
        $this->calculationEngine = new CalculationEngine($this->campaignService, $this->tierService);

        // Domain event services
        $firstOrderService = new FirstOrderRewardService($this->transactionService);
        $this->orderRewardService = new OrderRewardService($this->calculationEngine, $this->transactionService, $firstOrderService);
        $this->registrationRewardService = new RegistrationRewardService($this->transactionService);
        $this->reviewRewardService = new ReviewRewardService($this->transactionService);

        // Redemption & Checkout
        $this->redemptionService = new RedemptionService($this->balanceService);
        $this->classicCartRedemption = new ClassicCartRedemption($this->redemptionService, $this->transactionService);
        $this->blocksIntegration = new BlocksIntegration($this->redemptionService);
        $this->orderAdminIntegration = new OrderAdminIntegration($this->balanceService);
        $this->refundReversalService = new RefundReversalService($this->transactionService);

        // Expiration & Action Scheduler
        $this->expirationService = new ExpirationService($this->transactionService, $this->repository);

        // Customer area
        $this->myAccountRewardsArea = new MyAccountRewardsArea($this->balanceService, $this->repository, $this->tierService);

        // Administration & Reporting
        $this->customerAdminController = new CustomerAdminController($this->balanceService, $this->transactionService, $this->repository);
        $this->transactionAdminController = new TransactionAdminController($this->repository);
        $this->reportService = new ReportService($this->repository);
        $this->emailManager = new EmailManager();
        $this->licenseService = new LicenseService();
        $this->privacyManager = new PrivacyManager($this->repository, $this->balanceService);

        $this->settingsPage = new SettingsPage(
            $this->campaignService,
            $this->tierService,
            $this->customerAdminController,
            $this->transactionAdminController,
            $this->reportService,
            $this->licenseService,
            $this->expirationService,
            $this->balanceService,
            $this->repository
        );

        $this->adminMenu = new AdminMenu($this->settingsPage);
        $this->gitHubUpdater = new GitHubUpdater(
            WPCLR_PLUGIN_FILE,
            'wpcalibrate-loyalty-rewards',
            WPCLR_VERSION
        );
    }

    /**
     * Singleton accessor.
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Initialize all plugin subsystems and hooks.
     */
    public function init(): void
    {
        // 1. Text Domain
        load_plugin_textdomain(
            'wpcalibrate-loyalty-rewards',
            false,
            dirname(WPCLR_PLUGIN_BASENAME) . '/languages'
        );

        // 2. Ensure schema & database migrations
        MigrationManager::maybeMigrate();

        // 3. Register hooks across subsystems
        $this->orderRewardService->register();
        $this->registrationRewardService->register();
        $this->reviewRewardService->register();
        $this->classicCartRedemption->register();
        $this->blocksIntegration->register();
        $this->orderAdminIntegration->register();
        $this->refundReversalService->register();
        $this->expirationService->register();
        $this->myAccountRewardsArea->register();
        $this->emailManager->register();
        $this->privacyManager->register();

        if (is_admin()) {
            $this->adminMenu->register();
            $this->gitHubUpdater->register();
        }
    }

    // Service Getters
    public function getTransactionService(): TransactionService
    {
        return $this->transactionService;
    }

    public function getBalanceService(): BalanceService
    {
        return $this->balanceService;
    }

    public function getRepository(): TransactionRepository
    {
        return $this->repository;
    }

    public function getCalculationEngine(): CalculationEngine
    {
        return $this->calculationEngine;
    }

    public function getRedemptionService(): RedemptionService
    {
        return $this->redemptionService;
    }

    public function getCampaignService(): CampaignService
    {
        return $this->campaignService;
    }

    public function getTierService(): TierService
    {
        return $this->tierService;
    }
}
