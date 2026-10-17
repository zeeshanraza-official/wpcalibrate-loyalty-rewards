<?php
declare(strict_types=1);

namespace WPCalibrate\LoyaltyRewards\Activation;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles plugin deactivation routines safely preserving data.
 */
final class Deactivator
{
    /**
     * Run deactivation sequence.
     */
    public static function deactivate(): void
    {
        // 1. Unschedule recurring Action Scheduler actions
        if (function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions('wpcalibrate_loyalty_rewards_expire_points', [], 'wpcalibrate-loyalty-rewards');
            as_unschedule_all_actions('wpcalibrate_loyalty_rewards_send_expiry_warnings', [], 'wpcalibrate-loyalty-rewards');
        }

        // 2. Clear rewrite rules
        flush_rewrite_rules();

        // Note: Never delete customer points, settings, or transactions on deactivation.
    }
}
