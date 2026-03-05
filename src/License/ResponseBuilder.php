<?php

declare(strict_types=1);

namespace Supertab\Connect\License;

use Supertab\Connect\Enum\LicenseTokenInvalidReason;
use Supertab\Connect\Result\AllowResult;
use Supertab\Connect\Result\BlockResult;

final class ResponseBuilder
{
    /**
     * Build a result that signals a missing token in EnforcementMode::SOFT.
     * Returns headers indicating a license is required without blocking the request.
     */
    public static function buildSignalResult(string $requestUrl): AllowResult
    {
        $licenseLink = self::generateLicenseLink($requestUrl);

        return new AllowResult([
            'Link' => "<{$licenseLink}>; rel=\"license\"; type=\"application/rsl+xml\"",
            'X-RSL-Status' => 'token_required',
            'X-RSL-Reason' => 'missing',
        ]);
    }

    /**
     * Build a result that blocks the request due to an invalid or missing token.
     */
    public static function buildBlockResult(
        LicenseTokenInvalidReason|string $reason,
        string $error,
        string $requestUrl,
    ): BlockResult {
        ['rslError' => $rslError, 'status' => $status] = self::reasonToRslError($reason);
        $errorDescription = self::sanitizeHeaderValue($error);
        $licenseLink = self::generateLicenseLink($requestUrl);

        return new BlockResult(
            status: $status,
            body: "Access to this resource requires a valid license token. Error: {$rslError} - {$error}",
            headers: [
                'Content-Type' => 'text/plain; charset=UTF-8',
                'WWW-Authenticate' => "License error=\"{$rslError}\", error_description=\"{$errorDescription}\"",
                'Link' => "<{$licenseLink}>; rel=\"license\"; type=\"application/rsl+xml\"",
            ],
        );
    }

    /**
     * Construct license link URL based on the request URL.
     * 
     * @param string $requestUrl
     * @return string
     */
    public static function generateLicenseLink(string $requestUrl): string
    {
        $parsed = parse_url($requestUrl);

        if ($parsed === false || ! isset($parsed['scheme'], $parsed['host'])) {
            return '/license.xml';
        }

        $port = isset($parsed['port']) ? ":{$parsed['port']}" : '';

        return "{$parsed['scheme']}://{$parsed['host']}{$port}/license.xml";
    }

    /**
     * @return array{rslError: string, status: int}
     */
    private static function reasonToRslError(LicenseTokenInvalidReason|string $reason): array
    {
        if (is_string($reason)) {
            return ['rslError' => 'invalid_token', 'status' => 401];
        }

        return match ($reason) {
            LicenseTokenInvalidReason::MISSING_TOKEN,
            LicenseTokenInvalidReason::INVALID_ALG => ['rslError' => 'invalid_request', 'status' => 401],

            LicenseTokenInvalidReason::EXPIRED,
            LicenseTokenInvalidReason::SIGNATURE_VERIFICATION_FAILED,
            LicenseTokenInvalidReason::INVALID_HEADER,
            LicenseTokenInvalidReason::INVALID_PAYLOAD,
            LicenseTokenInvalidReason::INVALID_ISSUER => ['rslError' => 'invalid_token', 'status' => 401],

            LicenseTokenInvalidReason::INVALID_AUDIENCE => ['rslError' => 'insufficient_scope', 'status' => 403],

            LicenseTokenInvalidReason::SERVER_ERROR => ['rslError' => 'server_error', 'status' => 503],
        };
    }

    /**
     * Sanitize a string for safe use in an HTTP header quoted-string (RFC 7230).
     * Strips CR/LF to prevent header injection and escapes backslashes and quotes.
     */
    private static function sanitizeHeaderValue(string $value): string
    {
        $value = str_replace(["\r", "\n"], '', $value);
        $value = str_replace('\\', '\\\\', $value);
        $value = str_replace('"', '\\"', $value);

        return $value;
    }
}
