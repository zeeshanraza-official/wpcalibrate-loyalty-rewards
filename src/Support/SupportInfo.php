<?php
declare(strict_types=1);

namespace WPCalibrate\LoyaltyRewards\Support;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Provides official WPCalibrate brand contact information, support links, and diagnostic helpers.
 */
final class SupportInfo
{
    public const EMAIL = 'support@wpcalibrate.com';
    public const WEBSITE = 'https://wpcalibrate.com';
    public const MARKETPLACE = 'https://marketplace.wpcalibrate.com/';
    public const WHATSAPP = '+447474795976';
    public const WHATSAPP_URL = 'https://wa.me/447474795976';

    /**
     * Render the official WPCalibrate support and contact card.
     */
    public static function renderSupportCard(): void
    {
        ?>
        <div class="wpclr-card wpclr-support-card">
            <div class="wpclr-support-header">
                <img src="<?php echo esc_url(plugins_url('branding/icon-dark.png', WPCLR_PLUGIN_FILE)); ?>" alt="<?php esc_attr_e('WPCalibrate Brand Mark', 'wpcalibrate-loyalty-rewards'); ?>" class="wpclr-brand-badge" style="height: 38px; width: auto;" />
                <div>
                    <h3 style="margin: 0 0 4px;"><?php esc_html_e('WPCalibrate Direct Support', 'wpcalibrate-loyalty-rewards'); ?></h3>
                    <p style="margin: 0; color: #646970;"><?php esc_html_e('Official enterprise support directly from the engineering team.', 'wpcalibrate-loyalty-rewards'); ?></p>
                </div>
            </div>

            <div class="wpclr-support-grid">
                <div class="wpclr-support-item">
                    <span class="wpclr-support-icon" aria-hidden="true">💬</span>
                    <div>
                        <strong><?php esc_html_e('WhatsApp & Direct Phone', 'wpcalibrate-loyalty-rewards'); ?></strong>
                        <p><a href="<?php echo esc_url(self::WHATSAPP_URL); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html(self::WHATSAPP); ?></a></p>
                    </div>
                </div>

                <div class="wpclr-support-item">
                    <span class="wpclr-support-icon" aria-hidden="true">✉️</span>
                    <div>
                        <strong><?php esc_html_e('Email Support', 'wpcalibrate-loyalty-rewards'); ?></strong>
                        <p><a href="<?php echo esc_url('mailto:' . self::EMAIL); ?>"><?php echo esc_html(self::EMAIL); ?></a></p>
                    </div>
                </div>

                <div class="wpclr-support-item">
                    <span class="wpclr-support-icon" aria-hidden="true">🌐</span>
                    <div>
                        <strong><?php esc_html_e('Official Website', 'wpcalibrate-loyalty-rewards'); ?></strong>
                        <p><a href="<?php echo esc_url(self::WEBSITE); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html(self::WEBSITE); ?></a></p>
                    </div>
                </div>

                <div class="wpclr-support-item">
                    <span class="wpclr-support-icon" aria-hidden="true">🛍️</span>
                    <div>
                        <strong><?php esc_html_e('Plugin Marketplace', 'wpcalibrate-loyalty-rewards'); ?></strong>
                        <p><a href="<?php echo esc_url(self::MARKETPLACE); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html(self::MARKETPLACE); ?></a></p>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Gather safe diagnostic details for support assistance (no passwords, tokens, or plain keys).
     *
     * @return array<string, mixed>
     */
    public static function getSafeDiagnostics(): array
    {
        global $wp_version;

        return [
            'plugin_version'  => WPCLR_VERSION,
            'db_version'      => get_option('wpclr_db_version', '1.0.0'),
            'wordpress'       => $wp_version,
            'woocommerce'     => defined('WC_VERSION') ? WC_VERSION : 'N/A',
            'php_version'     => PHP_VERSION,
            'web_server'      => $_SERVER['SERVER_SOFTWARE'] ?? 'N/A',
            'hpos_enabled'    => class_exists('\Automattic\WooCommerce\Utilities\OrderUtil') && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled(),
            'action_scheduler'=> function_exists('as_schedule_recurring_action'),
            'memory_limit'    => ini_get('memory_limit'),
        ];
    }
}
