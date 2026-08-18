<?php

declare(strict_types=1);

namespace Supertab\Connect\Customer;

/**
 * Canonicalizes URL components the way the WHATWG URL parser (and therefore
 * the TypeScript SDK) does, so origin construction and content matching agree:
 * hosts are lowercased and punycoded (when ext-intl is available), default
 * ports are stripped, and dot segments (including their percent-encoded
 * forms) are resolved.
 */
final class UrlCanonicalizer
{
    /**
     * The canonical origin (scheme://host[:port]) of a URL, or null when it
     * has none.
     */
    public static function canonicalOrigin(string $url): ?string
    {
        $parsed = parse_url($url);
        if ($parsed === false || ! isset($parsed['scheme'], $parsed['host'])) {
            return null;
        }

        return strtolower($parsed['scheme']) . '://' . self::canonicalHostFromParts($parsed);
    }

    /**
     * The canonical host (host[:port], default port stripped) of a URL, or
     * null when it has none.
     */
    public static function canonicalHost(string $url): ?string
    {
        $parsed = parse_url($url);
        if ($parsed === false || ! isset($parsed['host'])) {
            return null;
        }

        return self::canonicalHostFromParts($parsed);
    }

    /**
     * The canonical host from an already-parsed URL.
     *
     * @param  array{scheme?: string, host: string, port?: int}  $parsed
     */
    public static function canonicalHostFromParts(array $parsed): string
    {
        $host = self::asciiHost((string) $parsed['host']);
        $scheme = isset($parsed['scheme']) ? strtolower((string) $parsed['scheme']) : '';

        if (isset($parsed['port']) && ! self::isDefaultPort($scheme, (int) $parsed['port'])) {
            $host .= ':' . $parsed['port'];
        }

        return $host;
    }

    /**
     * Resolve "." and ".." segments (and their percent-encoded forms) in an
     * absolute path, per the WHATWG URL path parser. ".." never pops above
     * the root, and a trailing dot segment keeps the trailing slash.
     */
    public static function resolveDotSegments(string $path): string
    {
        if (! str_contains(strtolower(str_ireplace('%2e', '.', $path)), '/.')) {
            return $path;
        }

        $segments = explode('/', $path);
        $lastIndex = count($segments) - 1;
        $output = [];

        foreach ($segments as $i => $segment) {
            $normalized = strtolower(str_ireplace('%2e', '.', $segment));

            if ($normalized === '..') {
                if (count($output) > 1) {
                    array_pop($output);
                }
                if ($i === $lastIndex) {
                    $output[] = '';
                }
            } elseif ($normalized === '.') {
                if ($i === $lastIndex) {
                    $output[] = '';
                }
            } else {
                $output[] = $segment;
            }
        }

        return implode('/', $output);
    }

    /**
     * Lowercase the host and punycode non-ASCII (IDN) hosts when ext-intl is
     * available; without intl the lowercased host is used as-is.
     */
    private static function asciiHost(string $host): string
    {
        $host = strtolower($host);

        if (function_exists('idn_to_ascii') && preg_match('/[^\x00-\x7F]/', $host) === 1) {
            $ascii = idn_to_ascii($host);
            if ($ascii !== false) {
                return $ascii;
            }
        }

        return $host;
    }

    private static function isDefaultPort(string $scheme, int $port): bool
    {
        return ($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80);
    }
}
