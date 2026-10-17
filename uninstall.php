<?php
/**
 * Fired when the plugin is uninstalled via WordPress.
 */

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Inspect plugin settings to check if data deletion was explicitly authorized
$settings = get_option('wpclr_settings', []);
$deleteData = !empty($settings['delete_on_uninstall']);

if (!$deleteData) {
    // By default, preserve all customer points, transaction records, and configurations
    return;
}

global $wpdb;

// Function to cleanup data for a specific blog
$cleanupBlog = static function () use ($wpdb): void {
    // 1. Delete custom database table
    $tableName = $wpdb->prefix . 'wpcalibrate_rewards_transactions';
    $wpdb->query("DROP TABLE IF EXISTS {$tableName};"); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

    // 2. Delete plugin options
    $optionsToDelete = [
        'wpclr_settings',
        'wpclr_tiers',
        'wpclr_campaigns',
        'wpclr_db_version',
        'wpclr_license_data',
    ];
    foreach ($optionsToDelete as $opt) {
        delete_option($opt);
    }

    // 3. Delete transients
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_wpclr_%' OR option_name LIKE '_transient_timeout_wpclr_%';"); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

    // 4. Delete user metadata specific to this plugin
    $wpdb->query("DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE 'wpclr_%';"); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

    // 5. Unschedule Action Scheduler tasks
    if (function_exists('as_unschedule_all_actions')) {
        as_unschedule_all_actions('wpcalibrate_loyalty_rewards_expire_points', [], 'wpcalibrate-loyalty-rewards');
        as_unschedule_all_actions('wpcalibrate_loyalty_rewards_send_expiry_warnings', [], 'wpcalibrate-loyalty-rewards');
    }
};

if (is_multisite()) {
    $blogIds = $wpdb->get_col("SELECT blog_id FROM {$wpdb->blogs}");
    foreach ($blogIds as $blogId) {
        switch_to_blog((int) $blogId);
        $cleanupBlog();
        restore_current_blog();
    }
} else {
    $cleanupBlog();
}
