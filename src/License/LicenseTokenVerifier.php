<?php

declare(strict_types=1);

namespace Supertab\Connect\License;

use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT;
use Firebase\JWT\SignatureInvalidException;
use Supertab\Connect\Enum\LicenseTokenInvalidReason;
use Supertab\Connect\Exception\JwksKeyNotFoundException;
use Supertab\Connect\Jwks\JwksProviderInterface;
use Supertab\Connect\Result\LicenseTokenVerificationResult;

final class LicenseTokenVerifier
{
    private const CLOCK_TOLERANCE_SECONDS = 60;

    public function __construct(
        private readonly JwksProviderInterface $jwksProvider,
        private readonly string $supertabBaseUrl,
        private readonly bool $debug = false,
    ) {}

    public function verify(string $licenseToken, string $requestUrl): LicenseTokenVerificationResult
    {
        if ($licenseToken === '') {
            return LicenseTokenVerificationResult::invalid(LicenseTokenInvalidReason::MISSING_TOKEN);
        }

        $header = $this->decodeHeader($licenseToken);
        if ($header === null) {
            return LicenseTokenVerificationResult::invalid(LicenseTokenInvalidReason::INVALID_HEADER);
        }

        if (($header['alg'] ?? null) !== 'ES256') {
            if ($this->debug) {
                error_log('[SupertabConnect] Unsupported license JWT alg: ' . ($header['alg'] ?? 'none'));
            }

            return LicenseTokenVerificationResult::invalid(LicenseTokenInvalidReason::INVALID_ALG);
        }

        $payload = $this->decodePayload($licenseToken);
        if ($payload === null) {
            return LicenseTokenVerificationResult::invalid(LicenseTokenInvalidReason::INVALID_PAYLOAD);
        }

        $licenseId = isset($payload['license_id']) && is_string($payload['license_id'])
            ? $payload['license_id']
            : null;

        // Validate issuer
        $issuer = isset($payload['iss']) && is_string($payload['iss']) ? $payload['iss'] : null;
        $normalizedIssuer = $issuer !== null ? $this->stripTrailingSlash($issuer) : null;
        $normalizedBaseUrl = $this->stripTrailingSlash($this->supertabBaseUrl);

        if ($normalizedIssuer === null || ! str_starts_with($normalizedIssuer, $normalizedBaseUrl)) {
            if ($this->debug) {
                error_log('[SupertabConnect] License JWT issuer is missing or malformed: ' . ($issuer ?? 'null'));
            }

            return LicenseTokenVerificationResult::invalid(LicenseTokenInvalidReason::INVALID_ISSUER, $licenseId);
        }

        // Validate audience (prefix match)
        $audienceValues = $this->extractAudience($payload);
        $normalizedRequestUrl = $this->stripTrailingSlash($requestUrl);

        $matchesRequestUrl = false;
        foreach ($audienceValues as $aud) {
            $normalizedAudience = $this->stripTrailingSlash($aud);
            if ($normalizedAudience !== '' && str_starts_with($normalizedRequestUrl, $normalizedAudience)) {
                $matchesRequestUrl = true;
                break;
            }
        }

        if (! $matchesRequestUrl) {
            if ($this->debug) {
                error_log('[SupertabConnect] License JWT audience does not match request URL: ' . json_encode($payload['aud'] ?? null));
            }

            return LicenseTokenVerificationResult::invalid(LicenseTokenInvalidReason::INVALID_AUDIENCE, $licenseId);
        }

        return $this->verifySignature($licenseToken, $header, $licenseId);
    }

    private function verifySignature(
        string $licenseToken,
        array $header,
        ?string $licenseId,
    ): LicenseTokenVerificationResult {
        $kid = $header['kid'] ?? null;

        $doVerify = function (bool $forceRefresh) use ($licenseToken, $kid, $licenseId): LicenseTokenVerificationResult {
            // Get the specific key by kid
            try {
                if ($kid !== null) {
                    $key = $this->jwksProvider->getKeyByKid($kid, $forceRefresh);
                    $keyOrKeyArray = [$kid => $key];
                } else {
                    $keyOrKeyArray = $this->jwksProvider->getKeys($forceRefresh);
                }
            } catch (JwksKeyNotFoundException $e) {
                throw $e;
            } catch (\Throwable $e) {
                if ($this->debug) {
                    error_log('[SupertabConnect] Failed to fetch platform JWKS: ' . $e->getMessage());
                }

                return LicenseTokenVerificationResult::invalid(LicenseTokenInvalidReason::SERVER_ERROR, $licenseId);
            }

            // Set clock tolerance (1 minute)
            $previousLeeway = JWT::$leeway;
            JWT::$leeway = self::CLOCK_TOLERANCE_SECONDS;

            try {
                $decoded = JWT::decode($licenseToken, $keyOrKeyArray);
                $decodedPayload = (array) $decoded;

                return LicenseTokenVerificationResult::valid($licenseId, $decodedPayload);
            } catch (ExpiredException $e) {
                return LicenseTokenVerificationResult::invalid(LicenseTokenInvalidReason::EXPIRED, $licenseId);
            } catch (SignatureInvalidException $e) {
                return LicenseTokenVerificationResult::invalid(LicenseTokenInvalidReason::SIGNATURE_VERIFICATION_FAILED, $licenseId);
            } catch (\Throwable $e) {
                if ($this->debug) {
                    error_log('[SupertabConnect] License JWT verification failed: ' . $e->getMessage());
                }

                return LicenseTokenVerificationResult::invalid(LicenseTokenInvalidReason::SIGNATURE_VERIFICATION_FAILED, $licenseId);
            } finally {
                JWT::$leeway = $previousLeeway;
            }
        };

        try {
            return $doVerify(false);
        } catch (JwksKeyNotFoundException) {
            // Key not found in cached JWKS — clear cache and retry once
            if ($this->debug) {
                error_log('[SupertabConnect] Key not found in cached JWKS, clearing cache and retrying...');
            }
            $this->jwksProvider->clearCache();

            return $doVerify(true);
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
     * Decode the JWT payload (second segment) without verification.
     *
     * @return array<string, mixed>|null
     */
    private function decodePayload(string $token): ?array
    {
        $segments = explode('.', $token);
        if (count($segments) !== 3) {
            return null;
        }

        try {
            $payload = json_decode(JWT::urlsafeB64Decode($segments[1]), true, 512, JSON_THROW_ON_ERROR);

            return is_array($payload) ? $payload : null;
        } catch (\JsonException) {
            return null;
        }
    }

    private function stripTrailingSlash(string $value): string
    {
        return rtrim(trim($value), '/');
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
