<?php

declare(strict_types=1);

namespace Supertab\Connect\Analytics;

/**
 * Normalizes a raw client IP into a canonical IPv6 representation for analytics.
 *
 * The normalization rules are as follows:
 *
 * IPv4 addresses are mapped to IPv6 (`::ffff:x.x.x.x`), valid IPv6 addresses
 * pass through unchanged, and anything else (empty, malformed) becomes the
 * unspecified address `::`.
 */
final class ClientIpNormalizer
{
    private const UNSPECIFIED = '::';

    public static function normalize(?string $raw): string
    {
        if ($raw === null) {
            return self::UNSPECIFIED;
        }

        $trimmed = trim($raw);
        if ($trimmed === '') {
            return self::UNSPECIFIED;
        }

        if (filter_var($trimmed, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return "::ffff:{$trimmed}";
        }

        if (filter_var($trimmed, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            return $trimmed;
        }

        return self::UNSPECIFIED;
    }
}
