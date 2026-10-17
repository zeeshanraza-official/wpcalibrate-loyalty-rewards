<?php
declare(strict_types=1);

namespace WPCalibrate\LoyaltyRewards\Customers;

use WPCalibrate\LoyaltyRewards\Points\TransactionService;
use WPCalibrate\LoyaltyRewards\Settings\SettingsManager;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles awarding points upon customer account registration.
 */
class RegistrationRewardService
{
    private TransactionService $transactionService;

    public function __construct(?TransactionService $transactionService = null)
    {
        $this->transactionService = $transactionService ?? new TransactionService();
    }

    /**
     * Register customer creation hooks.
     */
    public function register(): void
    {
        add_action('user_register', [$this, 'handleRegistration'], 20, 1);
        add_action('woocommerce_created_customer', [$this, 'handleRegistration'], 20, 1);
    }

    /**
     * Award registration bonus to newly created user.
     *
     * @param int $userId
     */
    public function handleRegistration(int $userId): void
    {
        if ($userId <= 0) {
            return;
        }

        if (!SettingsManager::get('registration_bonus_enabled', true)) {
            return;
        }

        $points = (int) SettingsManager::get('registration_bonus_points', 50);
        if ($points <= 0) {
            return;
        }

        // Idempotency: awardRegistrationBonus uses event_key 'earn_reg_{userId}'
        $this->transactionService->awardRegistrationBonus($userId, $points);
    }
}
