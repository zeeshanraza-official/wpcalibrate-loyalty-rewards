<?php
declare(strict_types=1);

namespace WPCalibrate\LoyaltyRewards\Database;

use WPCalibrate\LoyaltyRewards\Settings\SettingsManager;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles database versioning, repeatable migrations, and default options initialization.
 */
final class MigrationManager
{
    public const DB_VERSION_OPTION = 'wpclr_db_version';

    /**
     * Run all pending migrations if necessary.
     */
    public static function maybeMigrate(): void
    {
        $installedVersion = get_option(self::DB_VERSION_OPTION, '0.0.0');

        if (version_compare((string) $installedVersion, WPCLR_DB_VERSION, '<')) {
            self::runMigrations((string) $installedVersion);
        }
    }

    /**
     * Run the migration sequence.
     */
    public static function runMigrations(string $installedVersion): void
    {
        // 1. Ensure database table exists and matches latest schema
        Schema::createOrUpdateTable();

        // 2. Set default options if missing
        self::initDefaultOptions();

        // 3. Update database version
        update_option(self::DB_VERSION_OPTION, WPCLR_DB_VERSION, false);
    }

    /**
     * Initialize default plugin options if not yet set.
     */
    public static function initDefaultOptions(): void
    {
        if (false === get_option(SettingsManager::OPTION_NAME, false)) {
            update_option(SettingsManager::OPTION_NAME, SettingsManager::getDefaults(), false);
        }

        if (false === get_option('wpclr_tiers', false)) {
            update_option('wpclr_tiers', [
                'enabled' => false,
                'list'    => [
                    [
                        'id'         => 'bronze',
                        'name'       => 'Bronze',
                        'points'     => 0,
                        'multiplier' => 1.0,
                    ],
                    [
                        'id'         => 'silver',
                        'name'       => 'Silver',
                        'points'     => 500,
                        'multiplier' => 1.25,
                    ],
                    [
                        'id'         => 'gold',
                        'name'       => 'Gold',
                        'points'     => 1500,
                        'multiplier' => 1.5,
                    ],
                    [
                        'id'         => 'platinum',
                        'name'       => 'Platinum',
                        'points'     => 3000,
                        'multiplier' => 2.0,
                    ],
                ],
            ], false);
        }

        if (false === get_option('wpclr_campaigns', false)) {
            update_option('wpclr_campaigns', [], false);
        }
    }
}
