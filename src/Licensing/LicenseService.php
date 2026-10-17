<?php
declare(strict_types=1);

namespace WPCalibrate\LoyaltyRewards\Licensing;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Service managing license storage, masking, and validation adapter routing.
 */
class LicenseService
{
    public const OPTION_NAME = 'wpclr_license_data';

    private LicenseProviderInterface $provider;

    public function __construct(?LicenseProviderInterface $provider = null)
    {
        $defaultProvider = new NullLicenseProvider();
        /**
         * Filter to allow future WPCalibrate Marketplace licensing adapters to inject.
         *
         * @param LicenseProviderInterface $provider
         */
        $this->provider = apply_filters('wpcalibrate_loyalty_rewards_license_provider', $provider ?? $defaultProvider);
    }

    /**
     * Get saved license data.
     *
     * @return array<string, mixed>
     */
    public function getLicenseData(): array
    {
        $data = get_option(self::OPTION_NAME, []);
        return is_array($data) ? $data : [];
    }

    /**
     * Get masked license key for secure UI presentation.
     */
    public function getMaskedKey(): string
    {
        $data = $this->getLicenseData();
        $key = (string) ($data['license_key'] ?? '');

        if ($key === '') {
            return '';
        }

        $length = strlen($key);
        if ($length <= 8) {
            return str_repeat('*', $length);
        }

        $prefix = substr($key, 0, 4);
        $suffix = substr($key, -4);
        $middle = str_repeat('*', min(12, $length - 8));

        return $prefix . '-' . $middle . '-' . $suffix;
    }

    /**
     * Get current license status.
     */
    public function getStatus(): string
    {
        $data = $this->getLicenseData();
        return (string) ($data['status'] ?? 'inactive');
    }

    /**
     * Attempt license activation.
     */
    public function activate(string $licenseKey): LicenseResult
    {
        $cleanKey = sanitize_text_field(trim($licenseKey));

        if ($cleanKey === '') {
            return new LicenseResult(false, 'invalid', __('Please enter a valid license key.', 'wpcalibrate-loyalty-rewards'));
        }

        $result = $this->provider->activate($cleanKey);

        if ($result->isSuccess()) {
            update_option(self::OPTION_NAME, [
                'license_key'  => $cleanKey,
                'status'       => $result->getStatus(),
                'expires_at'   => $result->getExpiresAt(),
                'renewal_url'  => $result->getRenewalUrl(),
                'activated_at' => gmdate('Y-m-d H:i:s'),
            ], false);
        } else {
            // Update status if provided or keep inactive
            $currentData = $this->getLicenseData();
            $currentData['status'] = $result->getStatus();
            update_option(self::OPTION_NAME, $currentData, false);
        }

        return $result;
    }

    /**
     * Attempt license deactivation.
     */
    public function deactivate(): LicenseResult
    {
        $data = $this->getLicenseData();
        $key = (string) ($data['license_key'] ?? '');

        $result = $this->provider->deactivate($key);

        update_option(self::OPTION_NAME, [
            'license_key' => '',
            'status'      => 'inactive',
        ], false);

        return $result;
    }
}
