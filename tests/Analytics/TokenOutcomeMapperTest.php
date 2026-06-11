<?php

declare(strict_types=1);

namespace Supertab\Connect\Tests\Analytics;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Supertab\Connect\Analytics\Enum\TokenOutcome;
use Supertab\Connect\Analytics\TokenOutcomeMapper;
use Supertab\Connect\Enum\LicenseTokenInvalidReason;

final class TokenOutcomeMapperTest extends TestCase
{
    #[DataProvider('reasonToOutcomeProvider')]
    public function test_maps_reason_to_outcome(LicenseTokenInvalidReason $reason, TokenOutcome $expected): void
    {
        $this->assertSame($expected, TokenOutcomeMapper::fromReason($reason));
    }

    /**
     * @return array<string, array{LicenseTokenInvalidReason, TokenOutcome}>
     */
    public static function reasonToOutcomeProvider(): array
    {
        return [
            'missing token' => [LicenseTokenInvalidReason::MISSING_TOKEN, TokenOutcome::ABSENT],
            'expired' => [LicenseTokenInvalidReason::EXPIRED, TokenOutcome::EXPIRED],
            'signature failed' => [LicenseTokenInvalidReason::SIGNATURE_VERIFICATION_FAILED, TokenOutcome::INVALID_SIGNATURE],
            'invalid audience' => [LicenseTokenInvalidReason::INVALID_AUDIENCE, TokenOutcome::INVALID_AUDIENCE],
            'invalid issuer' => [LicenseTokenInvalidReason::INVALID_ISSUER, TokenOutcome::INVALID_ISSUER],
            'invalid header → malformed' => [LicenseTokenInvalidReason::INVALID_HEADER, TokenOutcome::MALFORMED],
            'invalid payload → malformed' => [LicenseTokenInvalidReason::INVALID_PAYLOAD, TokenOutcome::MALFORMED],
            'invalid alg → malformed' => [LicenseTokenInvalidReason::INVALID_ALG, TokenOutcome::MALFORMED],
            'server error' => [LicenseTokenInvalidReason::SERVER_ERROR, TokenOutcome::SERVER_ERROR],
        ];
    }

    public function test_every_invalid_reason_is_mapped(): void
    {
        foreach (LicenseTokenInvalidReason::cases() as $reason) {
            // Must not throw / must return a TokenOutcome for every reason
            $this->assertInstanceOf(TokenOutcome::class, TokenOutcomeMapper::fromReason($reason));
        }
    }

    public function test_wire_values_match_contract(): void
    {
        $this->assertSame('absent', TokenOutcome::ABSENT->value);
        $this->assertSame('valid', TokenOutcome::VALID->value);
        $this->assertSame('not_validated', TokenOutcome::NOT_VALIDATED->value);
        $this->assertSame('invalid_signature', TokenOutcome::INVALID_SIGNATURE->value);
        $this->assertSame('malformed', TokenOutcome::MALFORMED->value);
    }
}
