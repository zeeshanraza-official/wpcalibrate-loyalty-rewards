<?php
declare(strict_types=1);

namespace WPCalibrate\LoyaltyRewards\Activation;

use WPCalibrate\LoyaltyRewards\Database\MigrationManager;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles plugin activation routines.
 */
final class Activator
{
    /**
     * Run activation sequence.
     */
    public static function activate(): void
    {
        // 1. Run database setup & migrations
        MigrationManager::maybeMigrate();

        // 2. Register My Account rewrite endpoint
        add_rewrite_endpoint('rewards', EP_ROOT | EP_PAGES);
        flush_rewrite_rules();

        // 3. Schedule recurring Action Scheduler jobs if available
        self::scheduleRecurringJobs();
    }

    /**
     * Schedule background jobs using Action Scheduler if available.
     */
    public static function scheduleRecurringJobs(): void
    {
        if (function_exists('as_schedule_recurring_action')) {
            // Process point expiration daily at midnight
            if (false === as_next_scheduled_action('wpcalibrate_loyalty_rewards_expire_points')) {
                as_schedule_recurring_action(
                    strtotime('tomorrow midnight'),
                    DAY_IN_SECONDS,
                    'wpcalibrate_loyalty_rewards_expire_points',
                    [],
                    'wpcalibrate-loyalty-rewards'
                );
            }

            // Check and send expiry warning emails daily at 09:00 AM
            if (false === as_next_scheduled_action('wpcalibrate_loyalty_rewards_send_expiry_warnings')) {
                as_schedule_recurring_action(
                    strtotime('tomorrow 09:00:00'),
                    DAY_IN_SECONDS,
                    'wpcalibrate_loyalty_rewards_send_expiry_warnings',
                    [],
                    'wpcalibrate-loyalty-rewards'
                );
            }
        }
    }
}
