<?php
declare(strict_types=1);

namespace WPCalibrate\LoyaltyRewards\Licensing;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Null/Unconfigured licensing provider.
 * Explicitly informs users and administrators that external licensing requires a configured validation provider.
 */
class NullLicenseProvider implements LicenseProviderInterface
{
    public function isConfigured(): bool
    {
        return false;
    }

    public function activate(string $licenseKey): LicenseResult
    {
        return new LicenseResult(
            false,
            'unconfigured',
            __('License activation requires a configured licensing provider. No external licensing provider has been configured for this installation.', 'wpcalibrate-loyalty-rewards')
        );
    }

    public function deactivate(string $licenseKey): LicenseResult
    {
        return new LicenseResult(
            false,
            'unconfigured',
            __('License deactivation requires a configured licensing provider.', 'wpcalibrate-loyalty-rewards')
        );
    }

    public function check(string $licenseKey): LicenseResult
    {
        return new LicenseResult(
            false,
            'unconfigured',
            __('Licensing validation requires a configured licensing provider.', 'wpcalibrate-loyalty-rewards')
        );
    }
}
