<?php
declare(strict_types=1);

namespace WPCalibrate\LoyaltyRewards\Licensing;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Interface contract for licensing providers.
 */
interface LicenseProviderInterface
{
    /**
     * Check whether this provider is configured with valid credentials/endpoints.
     */
    public function isConfigured(): bool;

    /**
     * Activate a license key.
     */
    public function activate(string $licenseKey): LicenseResult;

    /**
     * Deactivate a license key.
     */
    public function deactivate(string $licenseKey): LicenseResult;

    /**
     * Check current license status and entitlement.
     */
    public function check(string $licenseKey): LicenseResult;
}
