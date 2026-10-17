<?php
declare(strict_types=1);

namespace WPCalibrate\LoyaltyRewards\Database;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Manages the custom database table definition and schema generation for the loyalty transaction ledger.
 */
final class Schema
{
    public const TABLE_NAME = 'wpcalibrate_rewards_transactions';

    /**
     * Get the full table name including WordPress db prefix.
     */
    public static function getTableName(): string
    {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_NAME;
    }

    /**
     * Get the SQL schema definition suitable for dbDelta.
     */
    public static function getTableSchema(): string
    {
        global $wpdb;
        $tableName = self::getTableName();
        $charsetCollate = $wpdb->get_charset_collate();

        return "CREATE TABLE {$tableName} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            points BIGINT NOT NULL,
            transaction_type VARCHAR(50) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'completed',
            source_type VARCHAR(50) NULL,
            source_id BIGINT UNSIGNED NULL,
            event_key VARCHAR(191) NULL,
            order_id BIGINT UNSIGNED NULL,
            product_id BIGINT UNSIGNED NULL,
            campaign_id BIGINT UNSIGNED NULL,
            parent_transaction_id BIGINT UNSIGNED NULL,
            description TEXT NULL,
            metadata LONGTEXT NULL,
            expires_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY idx_event_key (event_key),
            KEY idx_user_created (user_id, created_at),
            KEY idx_user_status (user_id, status),
            KEY idx_order_id (order_id),
            KEY idx_expires_at (expires_at),
            KEY idx_transaction_type (transaction_type),
            KEY idx_parent_id (parent_transaction_id)
        ) {$charsetCollate};";
    }

    /**
     * Execute dbDelta to create or update the table schema.
     */
    public static function createOrUpdateTable(): void
    {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $sql = self::getTableSchema();
        dbDelta($sql);
    }

    /**
     * Drop the custom table during uninstallation if requested.
     */
    public static function dropTable(): void
    {
        global $wpdb;
        $tableName = self::getTableName();
        $wpdb->query("DROP TABLE IF EXISTS {$tableName};"); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }
}
