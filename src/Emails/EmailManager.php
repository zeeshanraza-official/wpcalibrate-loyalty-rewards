<?php
declare(strict_types=1);

namespace WPCalibrate\LoyaltyRewards\Emails;

use WPCalibrate\LoyaltyRewards\Settings\SettingsManager;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Manages transactional WooCommerce-styled email notifications for loyalty and rewards events.
 */
class EmailManager
{
    /**
     * Register email hooks.
     */
    public function register(): void
    {
        add_action('wpcalibrate_loyalty_rewards_after_create_transaction', [$this, 'handleTransactionEmail'], 20, 2);
        add_action('wpcalibrate_loyalty_rewards_send_expiry_warning', [$this, 'sendPointsExpiringEmail'], 20, 3);
    }

    /**
     * Dispatch relevant email based on newly recorded transaction type.
     *
     * @param int                  $transactionId
     * @param array<string, mixed> $tx
     */
    public function handleTransactionEmail(int $transactionId, array $tx): void
    {
        $userId = (int) ($tx['user_id'] ?? 0);
        $points = (int) ($tx['points'] ?? 0);
        $type = (string) ($tx['transaction_type'] ?? '');
        $orderId = !empty($tx['order_id']) ? (int) $tx['order_id'] : 0;

        if ($userId <= 0) {
            return;
        }

        if (str_starts_with($type, 'earn_') && SettingsManager::get('email_points_earned_enabled', true)) {
            $this->sendPointsEarnedEmail($userId, $points, $orderId);
        } elseif ($type === 'redeem' && SettingsManager::get('email_points_redeemed_enabled', true)) {
            $this->sendPointsRedeemedEmail($userId, abs($points), $orderId);
        } elseif (($type === 'admin_credit' || $type === 'admin_debit') && SettingsManager::get('email_points_adjusted_enabled', true)) {
            $reason = (string) ($tx['description'] ?? '');
            $this->sendPointsAdjustedEmail($userId, $points, $reason);
        }
    }

    /**
     * Send Points Earned email.
     */
    public function sendPointsEarnedEmail(int $userId, int $points, int $orderId = 0): bool
    {
        $user = get_userdata($userId);
        if (!$user) {
            return false;
        }

        $pointLabel = SettingsManager::get('point_label_plural', 'Points');
        $subject = sprintf(
            /* translators: 1: Points earned, 2: Site title */
            __('You just earned %1$d loyalty %2$s on %3$s!', 'wpcalibrate-loyalty-rewards'),
            $points,
            $pointLabel,
            get_bloginfo('name')
        );

        $heading = __('Loyalty Points Earned!', 'wpcalibrate-loyalty-rewards');

        $body = sprintf(
            '<p>%s</p><p>%s</p><p><a href="%s" class="button">%s</a></p>',
            sprintf(
                /* translators: %s: Customer name */
                esc_html__('Hi %s,', 'wpcalibrate-loyalty-rewards'),
                esc_html($user->display_name)
            ),
            sprintf(
                /* translators: 1: Points, 2: Label */
                esc_html__('Great news! You have earned %1$s %2$s on your recent activity.', 'wpcalibrate-loyalty-rewards'),
                '<strong>+' . esc_html(number_format_i18n($points)) . '</strong>',
                esc_html($pointLabel)
            ),
            esc_url(wc_get_account_endpoint_url('rewards')),
            esc_html__('View Your Rewards Balance', 'wpcalibrate-loyalty-rewards')
        );

        return $this->sendFormattedEmail($user->user_email, $subject, $heading, $body);
    }

    /**
     * Send Points Redeemed confirmation email.
     */
    public function sendPointsRedeemedEmail(int $userId, int $points, int $orderId = 0): bool
    {
        $user = get_userdata($userId);
        if (!$user) {
            return false;
        }

        $pointLabel = SettingsManager::get('point_label_plural', 'Points');
        $subject = sprintf(
            /* translators: 1: Points, 2: Site name */
            __('You redeemed %1$d %2$s on %3$s', 'wpcalibrate-loyalty-rewards'),
            $points,
            $pointLabel,
            get_bloginfo('name')
        );

        $heading = __('Points Redeemed for Discount', 'wpcalibrate-loyalty-rewards');

        $body = sprintf(
            '<p>%s</p><p>%s</p>',
            sprintf(
                /* translators: %s: Customer name */
                esc_html__('Hi %s,', 'wpcalibrate-loyalty-rewards'),
                esc_html($user->display_name)
            ),
            sprintf(
                /* translators: 1: Points, 2: Order ID */
                esc_html__('You successfully redeemed %1$s points on Order #%2$d.', 'wpcalibrate-loyalty-rewards'),
                '<strong>' . esc_html(number_format_i18n($points)) . '</strong>',
                $orderId
            )
        );

        return $this->sendFormattedEmail($user->user_email, $subject, $heading, $body);
    }

    /**
     * Send Points Adjusted notification email.
     */
    public function sendPointsAdjustedEmail(int $userId, int $pointsDelta, string $reason): bool
    {
        $user = get_userdata($userId);
        if (!$user) {
            return false;
        }

        $subject = __('Your loyalty points balance has been updated', 'wpcalibrate-loyalty-rewards');
        $heading = __('Account Points Adjustment', 'wpcalibrate-loyalty-rewards');

        $deltaText = $pointsDelta > 0 ? '+' . number_format_i18n($pointsDelta) : number_format_i18n($pointsDelta);

        $body = sprintf(
            '<p>%s</p><p>%s</p><p><em>%s</em></p>',
            sprintf(
                /* translators: %s: Customer name */
                esc_html__('Hi %s,', 'wpcalibrate-loyalty-rewards'),
                esc_html($user->display_name)
            ),
            sprintf(
                /* translators: %s: Points delta */
                esc_html__('An administrative adjustment of %s points was applied to your rewards balance.', 'wpcalibrate-loyalty-rewards'),
                '<strong>' . esc_html($deltaText) . '</strong>'
            ),
            esc_html($reason)
        );

        return $this->sendFormattedEmail($user->user_email, $subject, $heading, $body);
    }

    /**
     * Send Expiry Warning notice email.
     */
    public function sendPointsExpiringEmail(int $userId, int $expiringPoints, string $expiryDate): bool
    {
        $user = get_userdata($userId);
        if (!$user) {
            return false;
        }

        $pointLabel = SettingsManager::get('point_label_plural', 'Points');
        $formattedDate = wp_date(get_option('date_format', 'Y-m-d'), strtotime($expiryDate));

        $subject = sprintf(
            /* translators: 1: Points, 2: Expiry date */
            __('Reminder: %1$d loyalty points expire on %2$s', 'wpcalibrate-loyalty-rewards'),
            $expiringPoints,
            $formattedDate
        );

        $heading = __('Your Loyalty Points Are Expiring Soon', 'wpcalibrate-loyalty-rewards');

        $body = sprintf(
            '<p>%s</p><p>%s</p><p><a href="%s" class="button">%s</a></p>',
            sprintf(
                /* translators: %s: Customer name */
                esc_html__('Hi %s,', 'wpcalibrate-loyalty-rewards'),
                esc_html($user->display_name)
            ),
            sprintf(
                /* translators: 1: Points, 2: Label, 3: Date */
                esc_html__('You have %1$s %2$s set to expire on %3$s. Don\'t let your rewards go to waste!', 'wpcalibrate-loyalty-rewards'),
                '<strong>' . esc_html(number_format_i18n($expiringPoints)) . '</strong>',
                esc_html($pointLabel),
                '<strong>' . esc_html($formattedDate) . '</strong>'
            ),
            esc_url(wc_get_page_permalink('shop')),
            esc_html__('Shop Now & Redeem Your Points', 'wpcalibrate-loyalty-rewards')
        );

        return $this->sendFormattedEmail($user->user_email, $subject, $heading, $body);
    }

    /**
     * Render message within WooCommerce email wrapper and send via wc_mail.
     */
    public function sendFormattedEmail(string $to, string $subject, string $heading, string $content): bool
    {
        if (!function_exists('wc_mail')) {
            return wp_mail($to, $subject, wp_strip_all_tags($content));
        }

        $mailer = WC()->mailer();
        $wrappedContent = $mailer->wrap_message($heading, $content);

        return (bool) $mailer->send($to, $subject, $wrappedContent, "Content-Type: text/html\r\n");
    }
}
