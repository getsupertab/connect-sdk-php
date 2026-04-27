<?php

declare(strict_types=1);

namespace Supertab\Connect\Http;

/**
 * Converts incoming request headers into event properties for analytics.
 *
 * Header names are lowercased and prefixed with `h_`; sensitive or
 * duplicate headers (credentials, RFC 7239 `Forwarded`, user-agent) are
 * filtered out. Other client-IP headers (`X-Forwarded-For`, `X-Real-IP`,
 * `CF-Connecting-IP`, `True-Client-IP`) flow through — events are only
 * recorded for token-bearing (bot) requests, so these are bot-traffic
 * analytics signals rather than human PII.
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
        // RFC 7239 — can carry richer PII (`for=...;by=...;host=...`) than
        // the simpler client-IP headers; keep filtered.
        'forwarded',
    ];

    /**
     * Non-string header keys or values are skipped
     * (e.g. multi-value headers represented as `array<string, string[]>` by
     * some frameworks should be pre-joined by the caller).
     *
     * @param  array<string, string>  $headers
     * @return array<string, string>
     */
    public static function toEventProperties(array $headers): array
    {
        $result = [];
        foreach ($headers as $key => $value) {
            if (! is_string($key) || ! is_string($value)) {
                continue;
            }
            $lowerKey = strtolower($key);
            if (in_array($lowerKey, self::DENIED_HEADERS, true)) {
                continue;
            }
            $result['h_' . $lowerKey] = $value;
        }

        return $result;
    }
}
