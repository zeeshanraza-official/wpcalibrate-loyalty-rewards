<?php
declare(strict_types=1);

namespace WPCalibrate\LoyaltyRewards\Campaigns;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Manages promotional multiplier campaigns (e.g. Double Points Weekend).
 */
class CampaignService
{
    public const OPTION_NAME = 'wpclr_campaigns';

    /**
     * Get all configured campaigns.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getCampaigns(): array
    {
        $campaigns = get_option(self::OPTION_NAME, []);
        return is_array($campaigns) ? $campaigns : [];
    }

    /**
     * Save campaigns list.
     *
     * @param array<int, array<string, mixed>> $campaigns
     */
    public function saveCampaigns(array $campaigns): void
    {
        update_option(self::OPTION_NAME, array_values($campaigns), false);
    }

    /**
     * Find active campaigns for a given product and category context.
     * Evaluates campaign start/end against current site local time.
     *
     * @param int       $productId   Product ID (optional).
     * @param int[]     $categoryIds Category IDs (optional).
     * @return array<int, array<string, mixed>>
     */
    public function getActiveCampaigns(int $productId = 0, array $categoryIds = []): array
    {
        $all = $this->getCampaigns();
        if (empty($all)) {
            return [];
        }

        $nowTimestamp = current_datetime()->getTimestamp();
        $active = [];

        foreach ($all as $campaign) {
            if (empty($campaign['enabled'])) {
                continue;
            }

            // Check start date/time
            if (!empty($campaign['start_datetime'])) {
                $start = strtotime((string) $campaign['start_datetime']);
                if ($start && $nowTimestamp < $start) {
                    continue;
                }
            }

            // Check end date/time
            if (!empty($campaign['end_datetime'])) {
                $end = strtotime((string) $campaign['end_datetime']);
                if ($end && $nowTimestamp > $end) {
                    continue;
                }
            }

            // Check targeting scope: 'all', 'products', 'categories'
            $scope = $campaign['scope'] ?? 'all';

            if ($scope === 'products' && $productId > 0) {
                $targetProducts = array_map('absint', (array) ($campaign['product_ids'] ?? []));
                if (!in_array($productId, $targetProducts, true)) {
                    continue;
                }
            } elseif ($scope === 'categories' && !empty($categoryIds)) {
                $targetCategories = array_map('absint', (array) ($campaign['category_ids'] ?? []));
                if (empty(array_intersect($categoryIds, $targetCategories))) {
                    continue;
                }
            } elseif ($scope !== 'all' && $productId === 0 && empty($categoryIds)) {
                // Specific scope but called in a purely global context
                continue;
            }

            $active[] = $campaign;
        }

        return $active;
    }

    /**
     * Get the highest applicable multiplier.
     * Highest multiplier wins (e.g. 2x vs 3x -> 3.0x).
     *
     * @param int   $productId
     * @param int[] $categoryIds
     * @return float Multiplier (minimum 1.0)
     */
    public function getMultiplier(int $productId = 0, array $categoryIds = []): float
    {
        $active = $this->getActiveCampaigns($productId, $categoryIds);
        if (empty($active)) {
            return 1.0;
        }

        $maxMultiplier = 1.0;
        foreach ($active as $campaign) {
            $mult = (float) ($campaign['multiplier'] ?? 1.0);
            if ($mult > $maxMultiplier) {
                $maxMultiplier = $mult;
            }
        }

        /**
         * Filter the resolved campaign multiplier.
         *
         * @param float $maxMultiplier Resolved multiplier.
         * @param int   $productId     Product ID.
         * @param int[] $categoryIds   Category IDs.
         */
        return max(1.0, (float) apply_filters('wpcalibrate_loyalty_rewards_campaign_multiplier', $maxMultiplier, $productId, $categoryIds));
    }
}
