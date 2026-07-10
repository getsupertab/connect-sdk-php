<?php

declare(strict_types=1);

namespace Supertab\Connect\Status;

use Firebase\JWT\JWT;
use Supertab\Connect\Exception\JwksKeyNotFoundException;
use Supertab\Connect\Jwks\JwksProviderInterface;

/**
 * Verifies a backend-minted status-probe challenge.
 *
 * The probe carries an ES256 JWT (Authorization: Bearer) with purpose
 * "status-probe" and an audience equal to the request origin. Verification
 * reuses the platform JWKS the SDK already fetches for license tokens — no new
 * keys or crypto — including a clear-cache-and-retry-once so a signing-key
 * rotation doesn't fail probes against a stale JWKS cache.
 *
 * Mirrors the TypeScript SDK's verifyStatusChallenge(): it never throws; any
 * failure (malformed token, wrong claims, missing key, bad signature) resolves
 * to false.
 */
final class StatusChallengeVerifier
{
    private const CLOCK_TOLERANCE_SECONDS = 5;

    private const EXPECTED_PURPOSE = 'status-probe';

    public function __construct(
        private readonly JwksProviderInterface $jwksProvider,
        private readonly bool $debug = false,
    ) {}

    public function verify(string $token, string $expectedAudience): bool
    {
        $header = $this->decodeHeader($token);
        if ($header === null || ($header['alg'] ?? null) !== 'ES256') {
            return false;
        }

        $kid = isset($header['kid']) && is_string($header['kid']) ? $header['kid'] : null;
        if ($kid === null) {
            return false;
        }

        $doVerify = function (bool $forceRefresh) use ($token, $kid, $expectedAudience): bool {
            $key = $this->jwksProvider->getKeyByKid($kid, $forceRefresh);

            $previousLeeway = JWT::$leeway;
            JWT::$leeway = self::CLOCK_TOLERANCE_SECONDS;

            try {
                $payload = (array) JWT::decode($token, [$kid => $key]);
            } finally {
                JWT::$leeway = $previousLeeway;
            }

            if (($payload['purpose'] ?? null) !== self::EXPECTED_PURPOSE) {
                return false;
            }

            return in_array($expectedAudience, $this->extractAudience($payload), true);
        };

        try {
            return $doVerify(false);
        } catch (JwksKeyNotFoundException) {
            // Key not found in cached JWKS — clear cache and retry once, so a
            // signing-key rotation doesn't fail probes against a stale cache.
            if ($this->debug) {
                error_log('[SupertabConnect] Status challenge key not found in cached JWKS, clearing cache and retrying...');
            }
            $this->jwksProvider->clearCache();

            try {
                return $doVerify(true);
            } catch (\Throwable $e) {
                if ($this->debug) {
                    error_log('[SupertabConnect] Status challenge verification failed after JWKS refresh: ' . $e->getMessage());
                }

                return false;
            }
        } catch (\Throwable $e) {
            if ($this->debug) {
                error_log('[SupertabConnect] Status challenge verification failed: ' . $e->getMessage());
            }

            return false;
        }
    }

    /**
     * Decode the JWT header (first segment) without verification.
     *
     * @return array<string, mixed>|null
     */
    private function decodeHeader(string $token): ?array
    {
        $segments = explode('.', $token);
        if (count($segments) !== 3) {
            return null;
        }

        try {
            $header = json_decode(JWT::urlsafeB64Decode($segments[0]), true, 512, JSON_THROW_ON_ERROR);

            return is_array($header) ? $header : null;
        } catch (\JsonException) {
            return null;
        }
    }

    /**
     * Extract audience values as a flat array of strings.
     *
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    private function extractAudience(array $payload): array
    {
        $aud = $payload['aud'] ?? null;

        if (is_string($aud)) {
            return [$aud];
        }

        if (is_array($aud)) {
            return array_values(array_filter($aud, 'is_string'));
        }

        return [];
    }
}
