<?php

declare(strict_types=1);

namespace Supertab\Connect\Http;

/**
 * Converts incoming request headers into event properties for analytics.
 *
 * Ports src/headers.ts from connect-sdk-typescript. Header names are
 * lowercased and prefixed with `h_`; sensitive or duplicate headers
 * (credentials, client IPs, user-agent) are filtered out.
 */
final class Headers
{
    private const DENIED_HEADERS = [
        // Credentials
        'authorization',
        'cookie',
        'set-cookie',
        'proxy-authorization',
        'x-api-key',
        'x-amz-security-token',
        // Already captured as properties.user_agent — avoid duplication
        'user-agent',
        // SDK-internal plumbing (not useful as analytics signal)
        'x-license-auth',
        // Client IP / PII
        'x-forwarded-for',
        'x-real-ip',
        'cf-connecting-ip',
        'true-client-ip',
    ];

    /**
     * @param  array<string, string>  $headers
     * @return array<string, string>
     */
    public static function toEventProperties(array $headers): array
    {
        $result = [];
        foreach ($headers as $key => $value) {
            $lowerKey = strtolower($key);
            if (in_array($lowerKey, self::DENIED_HEADERS, true)) {
                continue;
            }
            $result['h_' . $lowerKey] = $value;
        }

        return $result;
    }
}
