<?php
declare(strict_types=1);

namespace WPCalibrate\LoyaltyRewards\Admin;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles WordPress Admin Menu registration under the shared WPCalibrate top-level menu.
 */
class AdminMenu
{
    public const PARENT_SLUG = 'wpcalibrate';
    public const PAGE_SLUG = 'wpcalibrate-loyalty-rewards';

    private SettingsPage $settingsPage;

    public function __construct(SettingsPage $settingsPage)
    {
        $this->settingsPage = $settingsPage;
    }

    /**
     * Register hooks.
     */
    public function register(): void
    {
        add_action('admin_menu', [$this, 'addAdminMenus'], 26);
        add_action('admin_head', [$this, 'addMenuStyles']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
    }

    /**
     * Add admin menus and submenus safely without duplicating parent or mixing sibling plugin settings.
     */
    public function addAdminMenus(): void
    {
        global $menu;

        // Check if top-level 'wpcalibrate' menu already exists from another WPCalibrate plugin
        $parentExists = false;
        if (is_array($menu)) {
            foreach ($menu as $item) {
                if (isset($item[2]) && $item[2] === self::PARENT_SLUG) {
                    $parentExists = true;
                    break;
                }
            }
        }

        $iconFile = file_exists(WPCLR_PLUGIN_DIR . 'branding/icon-menu.png') ? 'branding/icon-menu.png' : 'branding/icon-white.png';
        $iconUrl = plugins_url($iconFile, WPCLR_PLUGIN_FILE);

        if (!$parentExists) {
            add_menu_page(
                __('WPCalibrate', 'wpcalibrate-loyalty-rewards'),
                __('WPCalibrate', 'wpcalibrate-loyalty-rewards'),
                'manage_woocommerce',
                self::PARENT_SLUG,
                [$this->settingsPage, 'render'],
                $iconUrl,
                57
            );
        }

        // Register this plugin's unique submenu
        add_submenu_page(
            self::PARENT_SLUG,
            __('Loyalty & Rewards', 'wpcalibrate-loyalty-rewards'),
            __('Loyalty & Rewards', 'wpcalibrate-loyalty-rewards'),
            'manage_woocommerce',
            self::PAGE_SLUG,
            [$this->settingsPage, 'render']
        );
    }

    /**
     * Output global admin menu styles to ensure WPCalibrate icon perfectly matches WordPress core icon dimensions and colors.
     */
    public function addMenuStyles(): void
    {
        ?>
        <style id="wpclr-admin-menu-icon-styles">
            #adminmenu li.toplevel_page_wpcalibrate > a.menu-top,
            #adminmenu a[href*="page=wpcalibrate"].menu-top {
                display: flex !important;
                align-items: center !important;
                min-height: 34px !important;
            }
            #adminmenu .toplevel_page_wpcalibrate div.wp-menu-image,
            #adminmenu a[href*="page=wpcalibrate"] div.wp-menu-image {
                display: flex !important;
                align-items: center !important;
                justify-content: center !important;
                float: none !important;
                width: 36px !important;
                height: 34px !important;
                margin: 0 !important;
                padding: 0 !important;
                line-height: 1 !important;
            }
            #adminmenu .toplevel_page_wpcalibrate div.wp-menu-image img,
            #adminmenu a[href*="page=wpcalibrate"] div.wp-menu-image img {
                width: 20px !important;
                height: 20px !important;
                max-width: 20px !important;
                max-height: 20px !important;
                padding: 0 !important;
                margin: 0 !important;
                display: block !important;
                box-sizing: border-box !important;
                opacity: 1 !important;
                filter: none !important;
                transition: opacity 0.15s ease-in-out;
            }
            #adminmenu .toplevel_page_wpcalibrate div.wp-menu-name,
            #adminmenu a[href*="page=wpcalibrate"] div.wp-menu-name {
                padding: 0 8px 0 0 !important;
                line-height: 34px !important;
                display: flex !important;
                align-items: center !important;
            }
            .folded #adminmenu .toplevel_page_wpcalibrate div.wp-menu-image,
            .folded #adminmenu a[href*="page=wpcalibrate"] div.wp-menu-image {
                width: 36px !important;
                height: 34px !important;
                padding: 0 !important;
                margin: 0 !important;
            }
            .folded #adminmenu .toplevel_page_wpcalibrate div.wp-menu-image img,
            .folded #adminmenu a[href*="page=wpcalibrate"] div.wp-menu-image img {
                padding: 0 !important;
                margin: 0 !important;
            }
            @media screen and (max-width: 782px) {
                #adminmenu li.toplevel_page_wpcalibrate > a.menu-top,
                #adminmenu a[href*="page=wpcalibrate"].menu-top {
                    min-height: 48px !important;
                }
                #adminmenu .toplevel_page_wpcalibrate div.wp-menu-image,
                #adminmenu a[href*="page=wpcalibrate"] div.wp-menu-image {
                    width: 48px !important;
                    height: 48px !important;
                }
                #adminmenu .toplevel_page_wpcalibrate div.wp-menu-name,
                #adminmenu a[href*="page=wpcalibrate"] div.wp-menu-name {
                    line-height: 48px !important;
                }
            }
        </style>
        <?php
    }

    /**
     * Enqueue admin assets strictly on this plugin's screen.
     *
     * @param string $hook
     */
    public function enqueueAssets(string $hook): void
    {
        // Only load on WPCalibrate Loyalty & Rewards screen
        if (!str_contains($hook, self::PAGE_SLUG)) {
            return;
        }

        wp_enqueue_style(
            'wpclr-admin-css',
            WPCLR_PLUGIN_URL . 'assets/css/admin.css',
            [],
            WPCLR_VERSION
        );

        wp_enqueue_script(
            'wpclr-admin-js',
            WPCLR_PLUGIN_URL . 'assets/js/admin.js',
            ['jquery'],
            WPCLR_VERSION,
            true
        );

        wp_localize_script('wpclr-admin-js', 'wpclrAdmin', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('wpclr_admin_nonce'),
            'i18n'    => [
                'confirmAdjustment' => __('Are you sure you want to adjust points for this customer?', 'wpcalibrate-loyalty-rewards'),
                'confirmDelete'     => __('Are you sure you want to delete this item? This action cannot be undone.', 'wpcalibrate-loyalty-rewards'),
                'copied'            => __('Copied to clipboard!', 'wpcalibrate-loyalty-rewards'),
            ],
        ]);
    }
}
