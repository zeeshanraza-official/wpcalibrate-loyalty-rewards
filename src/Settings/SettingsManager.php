<?php
declare(strict_types=1);

namespace WPCalibrate\LoyaltyRewards\Settings;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Manages plugin configuration, options persistence, defaults, and sanitization.
 */
final class SettingsManager
{
    public const OPTION_NAME = 'wpclr_settings';

    /**
     * Get default plugin settings.
     *
     * @return array<string, mixed>
     */
    public static function getDefaults(): array
    {
        return [
            // General / Presentation
            'point_label_singular'          => 'Point',
            'point_label_plural'            => 'Points',

            // Earning Rules
            'points_per_currency'           => 1.0, // 1 point per currency unit
            'eligible_order_statuses'       => ['completed'],
            'calculation_basis'             => 'subtotal_ex_tax_shipping', // subtotal_ex_tax_shipping, subtotal_inc_tax, total_inc_tax_shipping
            'sale_items_earn'               => true,
            'excluded_products'             => [],
            'excluded_categories'           => [],
            'rounding_policy'               => 'round', // round, floor, ceil

            // Bonuses
            'registration_bonus_enabled'    => true,
            'registration_bonus_points'     => 50,
            'first_order_bonus_enabled'     => true,
            'first_order_bonus_points'      => 100,
            'review_bonus_enabled'          => true,
            'review_bonus_points'           => 25,

            // Redemption Rules
            'redemption_ratio'              => 100, // 100 points = 1 store currency unit ($0.01 per point)
            'min_redemption_points'         => 50,
            'max_redemption_points'         => 0, // 0 = unlimited
            'max_discount_percentage'       => 100, // up to 100% of eligible value
            'min_cart_subtotal'             => 0.0,
            'coupon_compatibility'          => 'allow_with_coupons', // allow_with_coupons, disallow_with_coupons
            'redemption_exclude_tax'        => true,
            'redemption_exclude_shipping'   => true,
            'redemption_excluded_products'   => [],
            'redemption_excluded_categories' => [],
            'redemption_exclude_sale_items' => false,

            // Expiry
            'expiry_enabled'                => false,
            'expiry_period_days'            => 365,
            'expiry_warning_days'           => 14,

            // Emails
            'email_points_earned_enabled'   => true,
            'email_points_redeemed_enabled' => true,
            'email_points_adjusted_enabled' => true,
            'email_points_expiring_enabled' => true,
            'email_tier_unlocked_enabled'   => true,

            // Advanced / Maintenance
            'delete_on_uninstall'           => false,
            'debug_logging'                 => false,
        ];
    }

    /**
     * Get all settings merged with defaults.
     *
     * @return array<string, mixed>
     */
    public static function getAll(): array
    {
        $saved = get_option(self::OPTION_NAME, []);
        if (!is_array($saved)) {
            $saved = [];
        }

        return array_merge(self::getDefaults(), $saved);
    }

    /**
     * Get a specific setting value.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        $all = self::getAll();
        if (array_key_exists($key, $all)) {
            return $all[$key];
        }

        return $default;
    }

    /**
     * Update settings array.
     *
     * @param array<string, mixed> $input
     */
    public static function updateAll(array $input): void
    {
        $sanitized = self::sanitize($input);
        update_option(self::OPTION_NAME, $sanitized, false);
    }

    /**
     * Sanitize settings with strict typing and allowlists.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public static function sanitize(array $input): array
    {
        $output = self::getAll();
        $section = $input['wpclr_settings_section'] ?? null;
        $isFullUpdate = ($section === null);

        if (isset($input['point_label_singular'])) {
            $output['point_label_singular'] = sanitize_text_field((string) $input['point_label_singular']);
        }
        if (isset($input['point_label_plural'])) {
            $output['point_label_plural'] = sanitize_text_field((string) $input['point_label_plural']);
        }

        // 1. Earning Section
        if ($section === 'earning' || $isFullUpdate || isset($input['points_per_currency']) || isset($input['review_bonus_points']) || isset($input['registration_bonus_points'])) {
            if (isset($input['points_per_currency'])) {
                $output['points_per_currency'] = max(0.0001, (float) $input['points_per_currency']);
            }
            if (isset($input['eligible_order_statuses']) && is_array($input['eligible_order_statuses'])) {
                $allowedStatuses = ['completed', 'processing', 'on-hold'];
                $output['eligible_order_statuses'] = array_values(array_intersect($allowedStatuses, array_map('sanitize_key', $input['eligible_order_statuses'])));
                if (empty($output['eligible_order_statuses'])) {
                    $output['eligible_order_statuses'] = ['completed'];
                }
            }
            if (isset($input['calculation_basis'])) {
                $allowedBasis = ['subtotal_ex_tax_shipping', 'subtotal_inc_tax', 'total_inc_tax_shipping'];
                $output['calculation_basis'] = in_array($input['calculation_basis'], $allowedBasis, true) ? $input['calculation_basis'] : 'subtotal_ex_tax_shipping';
            }
            if (isset($input['rounding_policy'])) {
                $allowedRounding = ['round', 'floor', 'ceil'];
                $output['rounding_policy'] = in_array($input['rounding_policy'], $allowedRounding, true) ? $input['rounding_policy'] : 'round';
            }
            $output['sale_items_earn'] = !empty($input['sale_items_earn']);

            if (isset($input['excluded_products'])) {
                $output['excluded_products'] = is_array($input['excluded_products']) ? array_map('absint', array_filter($input['excluded_products'])) : [];
            }
            if (isset($input['excluded_categories'])) {
                $output['excluded_categories'] = is_array($input['excluded_categories']) ? array_map('absint', array_filter($input['excluded_categories'])) : [];
            }

            // Bonuses
            $output['registration_bonus_enabled'] = !empty($input['registration_bonus_enabled']);
            $output['registration_bonus_points'] = max(0, absint($input['registration_bonus_points'] ?? 0));
            $output['first_order_bonus_enabled'] = !empty($input['first_order_bonus_enabled']);
            $output['first_order_bonus_points'] = max(0, absint($input['first_order_bonus_points'] ?? 0));
            $output['review_bonus_enabled'] = !empty($input['review_bonus_enabled']);
            $output['review_bonus_points'] = max(0, absint($input['review_bonus_points'] ?? 0));
        }

        // 2. Redemption Section
        if ($section === 'redemption' || $isFullUpdate || isset($input['redemption_ratio']) || isset($input['min_redemption_points'])) {
            if (isset($input['redemption_ratio'])) {
                $output['redemption_ratio'] = max(1, absint($input['redemption_ratio']));
            }
            $output['min_redemption_points'] = max(0, absint($input['min_redemption_points'] ?? 0));
            $output['max_redemption_points'] = max(0, absint($input['max_redemption_points'] ?? 0));
            $output['max_discount_percentage'] = min(100, max(1, absint($input['max_discount_percentage'] ?? 100)));
            $output['min_cart_subtotal'] = max(0.0, (float) ($input['min_cart_subtotal'] ?? 0.0));

            if (isset($input['coupon_compatibility'])) {
                $output['coupon_compatibility'] = $input['coupon_compatibility'] === 'disallow_with_coupons' ? 'disallow_with_coupons' : 'allow_with_coupons';
            }
            $output['redemption_exclude_tax'] = !empty($input['redemption_exclude_tax']);
            $output['redemption_exclude_shipping'] = !empty($input['redemption_exclude_shipping']);
            $output['redemption_exclude_sale_items'] = !empty($input['redemption_exclude_sale_items']);

            if (isset($input['redemption_excluded_products'])) {
                $output['redemption_excluded_products'] = is_array($input['redemption_excluded_products']) ? array_map('absint', array_filter($input['redemption_excluded_products'])) : [];
            }
            if (isset($input['redemption_excluded_categories'])) {
                $output['redemption_excluded_categories'] = is_array($input['redemption_excluded_categories']) ? array_map('absint', array_filter($input['redemption_excluded_categories'])) : [];
            }
        }

        // 3. Expiry Section
        if ($section === 'expiry' || $isFullUpdate || isset($input['expiry_period_days'])) {
            $output['expiry_enabled'] = !empty($input['expiry_enabled']);
            $output['expiry_period_days'] = max(1, absint($input['expiry_period_days'] ?? 365));
            $output['expiry_warning_days'] = max(1, absint($input['expiry_warning_days'] ?? 14));
        }

        // 4. Emails Section
        if ($section === 'emails' || $isFullUpdate || isset($input['email_points_earned_enabled']) || isset($input['email_tier_unlocked_enabled'])) {
            $output['email_points_earned_enabled'] = !empty($input['email_points_earned_enabled']);
            $output['email_points_redeemed_enabled'] = !empty($input['email_points_redeemed_enabled']);
            $output['email_points_adjusted_enabled'] = !empty($input['email_points_adjusted_enabled']);
            $output['email_points_expiring_enabled'] = !empty($input['email_points_expiring_enabled']);
            $output['email_tier_unlocked_enabled'] = !empty($input['email_tier_unlocked_enabled']);
        }

        // 5. Advanced Section
        if ($section === 'advanced' || $isFullUpdate || isset($input['delete_on_uninstall']) || isset($input['debug_logging'])) {
            $output['delete_on_uninstall'] = !empty($input['delete_on_uninstall']);
            $output['debug_logging'] = !empty($input['debug_logging']);
        }

        return $output;
    }
}
