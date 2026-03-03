<?php

declare(strict_types=1);

namespace Supertab\Connect\Result;

use Supertab\Connect\Enum\LicenseTokenInvalidReason;

final class LicenseTokenVerificationResult
{
    /**
     * @param  array<string, mixed>|null  $payload
     */
    private function __construct(
        public bool $valid,
        public ?LicenseTokenInvalidReason $reason = null,
        public ?string $error = null,
        public ?string $licenseId = null,
        public ?array $payload = null,
    ) {}

    /**
     * @param  array<string, mixed>|null  $payload
     */
    public static function valid(?string $licenseId = null, ?array $payload = null): self
    {
        return new self(
            valid: true,
            licenseId: $licenseId,
            payload: $payload,
        );
    }

    public static function invalid(
        LicenseTokenInvalidReason $reason,
        ?string $licenseId = null,
    ): self {
        return new self(
            valid: false,
            reason: $reason,
            error: $reason->toErrorDescription(),
            licenseId: $licenseId,
        );
    }
}
