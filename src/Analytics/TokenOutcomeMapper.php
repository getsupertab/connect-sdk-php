<?php

declare(strict_types=1);

namespace Supertab\Connect\Analytics;

use Supertab\Connect\Analytics\Enum\TokenOutcome;
use Supertab\Connect\Enum\LicenseTokenInvalidReason;

/**
 * Maps a {@see LicenseTokenInvalidReason} (the SDK's verification-failure
 * vocabulary) onto a {@see TokenOutcome} (the analytics wire vocabulary).
 *
 * Mirrors the TypeScript SDK's TOKEN_OUTCOME_BY_REASON table. Several distinct
 * reasons collapse to `malformed`, matching the TS behavior.
 */
final class TokenOutcomeMapper
{
    public static function fromReason(LicenseTokenInvalidReason $reason): TokenOutcome
    {
        return match ($reason) {
            LicenseTokenInvalidReason::MISSING_TOKEN => TokenOutcome::ABSENT,
            LicenseTokenInvalidReason::EXPIRED => TokenOutcome::EXPIRED,
            LicenseTokenInvalidReason::SIGNATURE_VERIFICATION_FAILED => TokenOutcome::INVALID_SIGNATURE,
            LicenseTokenInvalidReason::INVALID_AUDIENCE => TokenOutcome::INVALID_AUDIENCE,
            LicenseTokenInvalidReason::INVALID_ISSUER => TokenOutcome::INVALID_ISSUER,
            LicenseTokenInvalidReason::INVALID_HEADER,
            LicenseTokenInvalidReason::INVALID_PAYLOAD,
            LicenseTokenInvalidReason::INVALID_ALG => TokenOutcome::MALFORMED,
            LicenseTokenInvalidReason::SERVER_ERROR => TokenOutcome::SERVER_ERROR,
        };
    }
}
