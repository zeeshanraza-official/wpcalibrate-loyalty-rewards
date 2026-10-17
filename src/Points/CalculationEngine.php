<?php
declare(strict_types=1);

namespace WPCalibrate\LoyaltyRewards\Points;

use WPCalibrate\LoyaltyRewards\Campaigns\CampaignService;
use WPCalibrate\LoyaltyRewards\Settings\SettingsManager;
use WPCalibrate\LoyaltyRewards\Tiers\TierService;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Calculates reward points earned from orders, items, campaigns, and tiers.
 */
class CalculationEngine
{
    private CampaignService $campaignService;
    private TierService $tierService;

    public function __construct(
        ?CampaignService $campaignService = null,
        ?TierService $tierService = null
    ) {
        $this->campaignService = $campaignService ?? new CampaignService();
        $this->tierService = $tierService ?? new TierService();
    }

    /**
     * Calculate points earned from a WooCommerce order.
     *
     * @param \WC_Order $order
     * @return array{
     *   points: int,
     *   eligible_amount: float,
     *   base_points: float,
     *   tier_multiplier: float,
     *   campaign_multiplier: float,
     *   item_breakdown: array<int, array<string, mixed>>
     * }
     */
    public function calculateOrderPoints(\WC_Order $order): array
    {
        $userId = $order->get_customer_id();
        $rate = (float) SettingsManager::get('points_per_currency', 1.0);
        $basis = (string) SettingsManager::get('calculation_basis', 'subtotal_ex_tax_shipping');
        $saleItemsEarn = (bool) SettingsManager::get('sale_items_earn', true);
        $excludedProducts = array_map('absint', (array) SettingsManager::get('excluded_products', []));
        $excludedCategories = array_map('absint', (array) SettingsManager::get('excluded_categories', []));
        $roundingPolicy = (string) SettingsManager::get('rounding_policy', 'round');

        // Customer Tier Multiplier
        $tierInfo = $this->tierService->getCustomerTier($userId);
        $tierMultiplier = (float) ($tierInfo['multiplier'] ?? 1.0);

        $eligibleAmount = 0.0;
        $totalRawPoints = 0.0;
        $itemBreakdown = [];
        $maxCampaignMultiplierFound = 1.0;

        /** @var \WC_Order_Item_Product $item */
        foreach ($order->get_items('line_item') as $itemId => $item) {
            $productId = $item->get_product_id();
            $variationId = $item->get_variation_id();
            $effectiveProductId = $variationId > 0 ? $variationId : $productId;
            $product = $item->get_product();

            if (!$product) {
                continue;
            }

            // Excluded product check
            if (in_array($productId, $excludedProducts, true) || in_array($effectiveProductId, $excludedProducts, true)) {
                continue;
            }

            // Category exclusions check
            $itemCategoryIds = wp_get_post_terms($productId, 'product_cat', ['fields' => 'ids']);
            if (!empty(array_intersect($itemCategoryIds, $excludedCategories))) {
                continue;
            }

            // Sale item exclusion check
            if (!$saleItemsEarn && $product->is_on_sale()) {
                continue;
            }

            // Determine item monetary value based on calculation basis
            $lineSubtotal = (float) $item->get_subtotal();
            $lineTax = (float) $item->get_subtotal_tax();

            $itemAmount = match ($basis) {
                'subtotal_inc_tax'          => $lineSubtotal + $lineTax,
                'total_inc_tax_shipping'    => (float) $item->get_total() + (float) $item->get_total_tax(),
                default                     => $lineSubtotal, // subtotal_ex_tax_shipping
            };

            $eligibleAmount += $itemAmount;

            // Product-specific fixed point override or multiplier override
            $productOverridePoints = get_post_meta($effectiveProductId, '_wpclr_earn_points', true);
            if ($productOverridePoints === '' || $productOverridePoints === false) {
                $productOverridePoints = get_post_meta($productId, '_wpclr_earn_points', true);
            }

            $productOverrideMult = get_post_meta($effectiveProductId, '_wpclr_earn_multiplier', true);
            if ($productOverrideMult === '' || $productOverrideMult === false) {
                $productOverrideMult = get_post_meta($productId, '_wpclr_earn_multiplier', true);
            }
            $productMultiplier = ($productOverrideMult !== '' && $productOverrideMult !== false) ? (float) $productOverrideMult : 1.0;

            // Campaign multiplier for this product/category
            $campaignMultiplier = $this->campaignService->getMultiplier($productId, $itemCategoryIds);
            if ($campaignMultiplier > $maxCampaignMultiplierFound) {
                $maxCampaignMultiplierFound = $campaignMultiplier;
            }

            $itemRawPoints = 0.0;
            if ($productOverridePoints !== '' && $productOverridePoints !== false && (int) $productOverridePoints > 0) {
                // Fixed points per unit
                $qty = (int) $item->get_quantity();
                $itemRawPoints = ((int) $productOverridePoints * $qty) * $tierMultiplier * $campaignMultiplier;
            } else {
                // Rate based calculation
                $itemRawPoints = ($itemAmount * $rate) * $productMultiplier * $tierMultiplier * $campaignMultiplier;
            }

            $totalRawPoints += $itemRawPoints;

            $itemBreakdown[$itemId] = [
                'product_id'          => $productId,
                'name'                => $item->get_name(),
                'eligible_amount'     => $itemAmount,
                'campaign_multiplier' => $campaignMultiplier,
                'product_multiplier'  => $productMultiplier,
                'calculated_points'   => $itemRawPoints,
            ];
        }

        // Handle shipping fee if basis includes shipping
        if ($basis === 'total_inc_tax_shipping') {
            $shippingTotal = (float) $order->get_shipping_total() + (float) $order->get_shipping_tax();
            if ($shippingTotal > 0) {
                $eligibleAmount += $shippingTotal;
                $totalRawPoints += ($shippingTotal * $rate) * $tierMultiplier * $maxCampaignMultiplierFound;
            }
        }

        // Apply consistent rounding policy
        $finalPoints = match ($roundingPolicy) {
            'floor' => (int) floor($totalRawPoints),
            'ceil'  => (int) ceil($totalRawPoints),
            default => (int) round($totalRawPoints),
        };

        $finalPoints = max(0, $finalPoints);

        $result = [
            'points'              => $finalPoints,
            'eligible_amount'     => $eligibleAmount,
            'base_points'         => $totalRawPoints,
            'tier_multiplier'     => $tierMultiplier,
            'campaign_multiplier' => $maxCampaignMultiplierFound,
            'item_breakdown'      => $itemBreakdown,
        ];

        /**
         * Filter calculated order points.
         *
         * @param array     $result Final calculation summary.
         * @param \WC_Order $order  WooCommerce order object.
         */
        return apply_filters('wpcalibrate_loyalty_rewards_calculated_order_points', $result, $order);
    }
}
