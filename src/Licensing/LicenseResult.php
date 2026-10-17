<?php
declare(strict_types=1);

namespace WPCalibrate\LoyaltyRewards\Licensing;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Immutable value object representing the result of a licensing operation.
 */
final class LicenseResult
{
    public function __construct(
        private bool $success,
        private string $status,
        private string $message,
        private ?string $expiresAt = null,
        private ?string $renewalUrl = null
    ) {
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function getExpiresAt(): ?string
    {
        return $this->expiresAt;
    }

    public function getRenewalUrl(): ?string
    {
        return $this->renewalUrl;
    }
}
