<?php
declare(strict_types=1);

namespace WPCalibrate\LoyaltyRewards\Tiers;

use WPCalibrate\LoyaltyRewards\Database\TransactionRepository;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Manages loyalty tiers, qualification based on lifetime earned points, and tier multipliers.
 */
class TierService
{
    public const OPTION_NAME = 'wpclr_tiers';

    private TransactionRepository $repository;

    public function __construct(?TransactionRepository $repository = null)
    {
        $this->repository = $repository ?? new TransactionRepository();
    }

    /**
     * Get tier configuration data.
     *
     * @return array{enabled: bool, list: array<int, array<string, mixed>>}
     */
    public function getTierData(): array
    {
        $data = get_option(self::OPTION_NAME, []);
        if (!is_array($data)) {
            $data = [];
        }

        return [
            'enabled' => !empty($data['enabled']),
            'list'    => is_array($data['list'] ?? null) ? $data['list'] : [],
        ];
    }

    /**
     * Alias for getTierData for backward compatibility.
     *
     * @return array{enabled: bool, list: array<int, array<string, mixed>>}
     */
    public function getTiersConfig(): array
    {
        return $this->getTierData();
    }

    /**
     * Check if loyalty tiers are enabled.
     */
    public function isEnabled(): bool
    {
        return $this->getTierData()['enabled'];
    }

    /**
     * Save tier configuration.
     *
     * @param array{enabled?: bool, list?: array<int, array<string, mixed>>, tiers?: array<int, array<string, mixed>>} $data
     */
    public function saveTierData(array $data): void
    {
        // Support both 'list' and 'tiers' keys
        $rawList = (array) ($data['list'] ?? $data['tiers'] ?? []);
        $cleanList = [];

        foreach ($rawList as $item) {
            if (!is_array($item)) {
                continue;
            }
            $cleanList[] = [
                'id'         => (string) ($item['id'] ?? sanitize_title((string) ($item['name'] ?? 'tier'))),
                'name'       => (string) ($item['name'] ?? 'VIP Tier'),
                'points'     => max(0, absint($item['points'] ?? $item['threshold'] ?? 0)),
                'multiplier' => max(1.0, (float) ($item['multiplier'] ?? 1.0)),
            ];
        }

        // Sort tiers by required points ascending
        usort($cleanList, static function ($a, $b): int {
            return ($a['points'] ?? 0) <=> ($b['points'] ?? 0);
        });

        update_option(self::OPTION_NAME, [
            'enabled' => !empty($data['enabled']),
            'list'    => array_values($cleanList),
        ], false);
    }

    /**
     * Alias for saveTierData for backward compatibility.
     *
     * @param array<string, mixed> $data
     */
    public function saveTiersConfig(array $data): void
    {
        $this->saveTierData($data);
    }

    /**
     * Calculate and return customer's tier status.
     *
     * @param int $userId
     * @return array{
     *   enabled: bool,
     *   lifetime_points: int,
     *   current_tier: ?array<string, mixed>,
     *   next_tier: ?array<string, mixed>,
     *   points_needed: int,
     *   progress_percent: float,
     *   multiplier: float
     * }
     */
    public function getCustomerTier(int $userId): array
    {
        $data = $this->getTierData();
        $lifetimePoints = $userId > 0 ? $this->repository->calculateLifetimeEarned($userId) : 0;

        if (!$data['enabled'] || empty($data['list'])) {
            return [
                'enabled'          => false,
                'lifetime_points'  => $lifetimePoints,
                'current_tier'     => null,
                'next_tier'        => null,
                'points_needed'    => 0,
                'progress_percent' => 100.0,
                'multiplier'       => 1.0,
            ];
        }

        $tiers = $data['list'];
        // Ensure sorted by threshold points ASC
        usort($tiers, static function ($a, $b): int {
            return ($a['points'] ?? 0) <=> ($b['points'] ?? 0);
        });

        $currentTier = $tiers[0];
        $nextTier = null;

        foreach ($tiers as $tier) {
            $threshold = (int) ($tier['points'] ?? 0);
            if ($lifetimePoints >= $threshold) {
                $currentTier = $tier;
            } else {
                $nextTier = $tier;
                break;
            }
        }

        $multiplier = max(1.0, (float) ($currentTier['multiplier'] ?? 1.0));
        $pointsNeeded = 0;
        $progress = 100.0;

        if ($nextTier !== null) {
            $nextThreshold = (int) ($nextTier['points'] ?? 0);
            $currThreshold = (int) ($currentTier['points'] ?? 0);
            $pointsNeeded = max(0, $nextThreshold - $lifetimePoints);

            $range = max(1, $nextThreshold - $currThreshold);
            $currentInRange = max(0, $lifetimePoints - $currThreshold);
            $progress = min(100.0, round(($currentInRange / $range) * 100.0, 1));
        }

        $result = [
            'enabled'          => true,
            'lifetime_points'  => $lifetimePoints,
            'current_tier'     => $currentTier,
            'next_tier'        => $nextTier,
            'points_needed'    => $pointsNeeded,
            'progress_percent' => $progress,
            'multiplier'       => $multiplier,
        ];

        /**
         * Filter customer tier resolution.
         *
         * @param array $result Resolved tier details.
         * @param int   $userId Customer user ID.
         */
        return apply_filters('wpcalibrate_loyalty_rewards_customer_tier', $result, $userId);
    }
}
