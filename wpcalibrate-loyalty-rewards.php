<?php
/**
 * Plugin Name:       WPCalibrate Loyalty & Rewards
 * Plugin URI:        https://wpcalibrate.com
 * Description:       Enterprise-grade loyalty and rewards engine for WooCommerce stores with financial-grade ledger auditing, promotional multipliers, and block checkout support.
 * Version:           1.0.0
 * Requires at least: 6.7
 * Requires PHP:      8.2
 * Author:            WPCalibrate
 * Author URI:        https://wpcalibrate.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wpcalibrate-loyalty-rewards
 * Domain Path:       /languages
 * WC requires at least: 9.8
 * WC tested up to:   11.2.1
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

// Plugin constants
define('WPCLR_VERSION', '1.0.0');
define('WPCLR_DB_VERSION', '1.0.0');
define('WPCLR_PLUGIN_FILE', __FILE__);
define('WPCLR_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WPCLR_PLUGIN_URL', plugin_dir_url(__FILE__));
define('WPCLR_PLUGIN_BASENAME', plugin_basename(__FILE__));
define('WPCLR_PREFIX', 'wpclr_');
define('WPCLR_MIN_PHP_VERSION', '8.2.0');
define('WPCLR_MIN_WP_VERSION', '6.7');
define('WPCLR_MIN_WC_VERSION', '9.8');

// PSR-4 Autoloader
spl_autoload_register(static function (string $class): void {
    $prefix = 'WPCalibrate\\LoyaltyRewards\\';
    $baseDir = WPCLR_PLUGIN_DIR . 'src/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    if (file_exists($file)) {
        require_once $file;
    }
});

// HPOS & Cart/Checkout Blocks compatibility declaration
add_action('before_woocommerce_init', static function (): void {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', WPCLR_PLUGIN_FILE, true);
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', WPCLR_PLUGIN_FILE, true);
    }
});

/**
 * Check environmental requirements and initialize the plugin on plugins_loaded.
 */
add_action('plugins_loaded', static function (): void {
    // 1. PHP Version Check
    if (version_compare(PHP_VERSION, WPCLR_MIN_PHP_VERSION, '<')) {
        add_action('admin_notices', static function (): void {
            if (!current_user_can('activate_plugins')) {
                return;
            }
            printf(
                '<div class="notice notice-error"><p><strong>%1$s</strong> %2$s</p></div>',
                esc_html__('WPCalibrate Loyalty & Rewards Error:', 'wpcalibrate-loyalty-rewards'),
                sprintf(
                    /* translators: 1: Required PHP version, 2: Current PHP version */
                    esc_html__('PHP version %1$s or higher is required. You are currently running PHP %2$s. Please upgrade your PHP runtime.', 'wpcalibrate-loyalty-rewards'),
                    esc_html(WPCLR_MIN_PHP_VERSION),
                    esc_html(PHP_VERSION)
                )
            );
        });
        return;
    }

    // 2. WordPress Version Check
    global $wp_version;
    if (version_compare($wp_version, WPCLR_MIN_WP_VERSION, '<')) {
        add_action('admin_notices', static function (): void {
            if (!current_user_can('activate_plugins')) {
                return;
            }
            printf(
                '<div class="notice notice-error"><p><strong>%1$s</strong> %2$s</p></div>',
                esc_html__('WPCalibrate Loyalty & Rewards Error:', 'wpcalibrate-loyalty-rewards'),
                sprintf(
                    /* translators: 1: Required WordPress version */
                    esc_html__('WordPress version %1$s or higher is required. Please update WordPress.', 'wpcalibrate-loyalty-rewards'),
                    esc_html(WPCLR_MIN_WP_VERSION)
                )
            );
        });
        return;
    }

    // 3. WooCommerce Active & Version Check
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', static function (): void {
            if (!current_user_can('activate_plugins')) {
                return;
            }
            printf(
                '<div class="notice notice-warning is-dismissible"><p><strong>%1$s</strong> %2$s</p></div>',
                esc_html__('WPCalibrate Loyalty & Rewards Notice:', 'wpcalibrate-loyalty-rewards'),
                esc_html__('WooCommerce must be installed and active for loyalty points and rewards to function. Please activate WooCommerce.', 'wpcalibrate-loyalty-rewards')
            );
        });
        return;
    }

    if (defined('WC_VERSION') && version_compare(WC_VERSION, WPCLR_MIN_WC_VERSION, '<')) {
        add_action('admin_notices', static function (): void {
            if (!current_user_can('activate_plugins')) {
                return;
            }
            printf(
                '<div class="notice notice-warning is-dismissible"><p><strong>%1$s</strong> %2$s</p></div>',
                esc_html__('WPCalibrate Loyalty & Rewards Notice:', 'wpcalibrate-loyalty-rewards'),
                sprintf(
                    /* translators: 1: Required WooCommerce version, 2: Current WooCommerce version */
                    esc_html__('WooCommerce version %1$s or higher is recommended. Detected version: %2$s.', 'wpcalibrate-loyalty-rewards'),
                    esc_html(WPCLR_MIN_WC_VERSION),
                    esc_html(WC_VERSION)
                )
            );
        });
    }

    // Initialize the primary plugin instance
    \WPCalibrate\LoyaltyRewards\Plugin::getInstance()->init();
}, 10);

// Plugin action links
add_filter('plugin_action_links_' . WPCLR_PLUGIN_BASENAME, static function (array $links): array {
    $settingsUrl = admin_url('admin.php?page=wpcalibrate-loyalty-rewards');
    $supportUrl = admin_url('admin.php?page=wpcalibrate-loyalty-rewards&tab=support');

    $customLinks = [
        sprintf('<a href="%s">%s</a>', esc_url($settingsUrl), esc_html__('Settings', 'wpcalibrate-loyalty-rewards')),
        sprintf('<a href="%s">%s</a>', esc_url($supportUrl), esc_html__('Support', 'wpcalibrate-loyalty-rewards')),
    ];

    return array_merge($customLinks, $links);
});

// Lifecycle hooks
register_activation_hook(WPCLR_PLUGIN_FILE, static function (): void {
    \WPCalibrate\LoyaltyRewards\Activation\Activator::activate();
});

register_deactivation_hook(WPCLR_PLUGIN_FILE, static function (): void {
    \WPCalibrate\LoyaltyRewards\Activation\Deactivator::deactivate();
});
