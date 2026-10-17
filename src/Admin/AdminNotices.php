<?php
declare(strict_types=1);

namespace WPCalibrate\LoyaltyRewards\Admin;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Flash admin notices manager for WPCalibrate Loyalty & Rewards admin screens.
 */
final class AdminNotices
{
    private static array $notices = [];

    /**
     * Add success notice.
     */
    public static function addSuccess(string $message): void
    {
        self::$notices[] = ['type' => 'success', 'message' => $message];
    }

    /**
     * Add error notice.
     */
    public static function addError(string $message): void
    {
        self::$notices[] = ['type' => 'error', 'message' => $message];
    }

    /**
     * Render all queued notices.
     */
    public static function render(): void
    {
        foreach (self::$notices as $notice) {
            $typeClass = $notice['type'] === 'success' ? 'notice-success' : 'notice-error';
            printf(
                '<div class="notice %1$s is-dismissible" style="margin: 15px 0;"><p>%2$s</p></div>',
                esc_attr($typeClass),
                esc_html($notice['message'])
            );
        }
        self::$notices = [];
    }
}
