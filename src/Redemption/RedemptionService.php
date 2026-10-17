<?php
declare(strict_types=1);

namespace WPCalibrate\LoyaltyRewards\Redemption;

use WPCalibrate\LoyaltyRewards\Points\BalanceService;
use WPCalibrate\LoyaltyRewards\Settings\SettingsManager;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Domain engine for points redemption calculations, rules, constraints, and validation.
 */
class RedemptionService
{
    private BalanceService $balanceService;

    public function __construct(?BalanceService $balanceService = null)
    {
        $this->balanceService = $balanceService ?? new BalanceService();
    }

    /**
     * Get the balance service.
     */
    public function getBalanceService(): BalanceService
    {
        return $this->balanceService;
    }

    /**
     * Calculate maximum redeemable points and monetary value for a user on a given cart.
     *
     * @param int      $userId
     * @param \WC_Cart $cart
     * @return array{
     *   eligible: bool,
     *   customer_balance: int,
     *   min_points: int,
     *   max_points: int,
     *   max_discount: float,
     *   point_ratio: int,
     *   reason: ?string
     * }
     */
    public function getCartRedemptionLimits(int $userId, \WC_Cart $cart): array
    {
        if ($userId <= 0) {
            return [
                'eligible'         => false,
                'customer_balance' => 0,
                'min_points'       => 0,
                'max_points'       => 0,
                'max_discount'     => 0.0,
                'point_ratio'      => 100,
                'reason'           => __('You must be logged in to redeem loyalty points.', 'wpcalibrate-loyalty-rewards'),
            ];
        }

        $balance = $this->balanceService->getBalance($userId);
        $minPoints = (int) SettingsManager::get('min_redemption_points', 50);
        $ratio = (int) SettingsManager::get('redemption_ratio', 100); // e.g. 100 points = 1.0 currency unit

        if ($balance < $minPoints) {
            return [
                'eligible'         => false,
                'customer_balance' => $balance,
                'min_points'       => $minPoints,
                'max_points'       => 0,
                'max_discount'     => 0.0,
                'point_ratio'      => $ratio,
                'reason'           => sprintf(
                    /* translators: %d: Minimum points required */
                    __('A minimum of %d points is required to redeem rewards.', 'wpcalibrate-loyalty-rewards'),
                    $minPoints
                ),
            ];
        }

        // Coupon compatibility rule
        $couponCompatibility = (string) SettingsManager::get('coupon_compatibility', 'allow_with_coupons');
        if ($couponCompatibility === 'disallow_with_coupons' && !empty($cart->get_applied_coupons())) {
            return [
                'eligible'         => false,
                'customer_balance' => $balance,
                'min_points'       => $minPoints,
                'max_points'       => 0,
                'max_discount'     => 0.0,
                'point_ratio'      => $ratio,
                'reason'           => __('Reward points cannot be combined with promotional coupons on this order.', 'wpcalibrate-loyalty-rewards'),
            ];
        }

        // Calculate eligible cart subtotal
        $eligibleSubtotal = $this->calculateEligibleCartAmount($cart);
        $minCartSubtotal = (float) SettingsManager::get('min_cart_subtotal', 0.0);

        if ($eligibleSubtotal < $minCartSubtotal) {
            return [
                'eligible'         => false,
                'customer_balance' => $balance,
                'min_points'       => $minPoints,
                'max_points'       => 0,
                'max_discount'     => 0.0,
                'point_ratio'      => $ratio,
                'reason'           => sprintf(
                    /* translators: %s: Minimum cart amount */
                    __('A minimum eligible cart subtotal of %s is required to redeem points.', 'wpcalibrate-loyalty-rewards'),
                    wc_price($minCartSubtotal)
                ),
            ];
        }

        if ($eligibleSubtotal <= 0.0) {
            return [
                'eligible'         => false,
                'customer_balance' => $balance,
                'min_points'       => $minPoints,
                'max_points'       => 0,
                'max_discount'     => 0.0,
                'point_ratio'      => $ratio,
                'reason'           => __('No eligible items in cart for reward points redemption.', 'wpcalibrate-loyalty-rewards'),
            ];
        }

        // Maximum discount allowed by percentage (e.g. 100% or 50% of eligible subtotal)
        $maxPercent = (int) SettingsManager::get('max_discount_percentage', 100);
        $maxMonetaryDiscount = round($eligibleSubtotal * ($maxPercent / 100.0), 2);

        // Maximum points allowed based on monetary limit
        $maxPointsFromSubtotal = (int) floor($maxMonetaryDiscount * $ratio);

        // Apply admin max points cap if set
        $adminMaxPoints = (int) SettingsManager::get('max_redemption_points', 0);
        $effectiveMaxPoints = min($balance, $maxPointsFromSubtotal);
        if ($adminMaxPoints > 0) {
            $effectiveMaxPoints = min($effectiveMaxPoints, $adminMaxPoints);
        }

        if ($effectiveMaxPoints < $minPoints) {
            return [
                'eligible'         => false,
                'customer_balance' => $balance,
                'min_points'       => $minPoints,
                'max_points'       => 0,
                'max_discount'     => 0.0,
                'point_ratio'      => $ratio,
                'reason'           => __('Maximum redeemable points for this order is less than the minimum required threshold.', 'wpcalibrate-loyalty-rewards'),
            ];
        }

        $calculatedMaxDiscount = round($effectiveMaxPoints / $ratio, 2);

        return [
            'eligible'         => true,
            'customer_balance' => $balance,
            'min_points'       => $minPoints,
            'max_points'       => $effectiveMaxPoints,
            'max_discount'     => $calculatedMaxDiscount,
            'point_ratio'      => $ratio,
            'reason'           => null,
        ];
    }

    /**
     * Calculate eligible cart monetary value after applying exclusions.
     */
    public function calculateEligibleCartAmount(\WC_Cart $cart): float
    {
        $excludedProducts = array_map('absint', (array) SettingsManager::get('redemption_excluded_products', []));
        $excludedCategories = array_map('absint', (array) SettingsManager::get('redemption_excluded_categories', []));
        $excludeSaleItems = (bool) SettingsManager::get('redemption_exclude_sale_items', false);

        $eligibleAmount = 0.0;

        foreach ($cart->get_cart() as $cartItem) {
            $product = $cartItem['data'] ?? null;
            if (!$product instanceof \WC_Product) {
                continue;
            }

            $productId = $cartItem['product_id'];
            $variationId = $cartItem['variation_id'] ?? 0;
            $effectiveId = $variationId > 0 ? $variationId : $productId;

            // Product exclusion
            if (in_array($productId, $excludedProducts, true) || in_array($effectiveId, $excludedProducts, true)) {
                continue;
            }

            // Category exclusion
            $catIds = wp_get_post_terms($productId, 'product_cat', ['fields' => 'ids']);
            if (!empty(array_intersect($catIds, $excludedCategories))) {
                continue;
            }

            // Sale item exclusion
            if ($excludeSaleItems && $product->is_on_sale()) {
                continue;
            }

            $eligibleAmount += (float) ($cartItem['line_subtotal'] ?? 0.0);
        }

        return max(0.0, $eligibleAmount);
    }

    /**
     * Validate requested points against current cart and customer limits.
     *
     * @param int      $userId
     * @param int      $requestedPoints
     * @param \WC_Cart $cart
     * @return array{valid: bool, points: int, discount: float, message: string}
     */
    public function validateRedemption(int $userId, int $requestedPoints, \WC_Cart $cart): array
    {
        $limits = $this->getCartRedemptionLimits($userId, $cart);

        if (!$limits['eligible']) {
            return [
                'valid'    => false,
                'points'   => 0,
                'discount' => 0.0,
                'message'  => $limits['reason'] ?? __('Redemption is not eligible for this cart.', 'wpcalibrate-loyalty-rewards'),
            ];
        }

        $points = absint($requestedPoints);
        if ($points < $limits['min_points']) {
            return [
                'valid'    => false,
                'points'   => 0,
                'discount' => 0.0,
                'message'  => sprintf(
                    /* translators: %d: Minimum points */
                    __('You must redeem at least %d points.', 'wpcalibrate-loyalty-rewards'),
                    $limits['min_points']
                ),
            ];
        }

        // Cap to maximum allowed points
        $points = min($points, $limits['max_points']);
        $discount = round($points / $limits['point_ratio'], 2);

        return [
            'valid'    => true,
            'points'   => $points,
            'discount' => $discount,
            'message'  => sprintf(
                /* translators: 1: Points, 2: Discount money */
                __('Applied %1$d points for a %2$s reward discount.', 'wpcalibrate-loyalty-rewards'),
                $points,
                wc_price($discount)
            ),
        ];
    }
}
