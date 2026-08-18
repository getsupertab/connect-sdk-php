<?php

declare(strict_types=1);

namespace Supertab\Connect\Customer;

/**
 * Canonicalizes URL components the way the WHATWG URL parser (and therefore
 * the TypeScript SDK) does, so origin construction and content matching agree:
 * hosts are lowercased and punycoded (when ext-intl is available), default
 * ports are stripped, and dot segments (including their percent-encoded
 * forms) are resolved.
 *
 * IDN hosts are punycoded on the raw URL string BEFORE parse_url() runs:
 * parse_url() corrupts UTF-8 hosts whose continuation bytes fall in the
 * 0x80-0x9F range (e.g. the 0x9F in "ß" comes back as "_"), so punycoding
 * a parsed host is too late.
 */
final class UrlCanonicalizer
{
    /**
     * The canonical origin (scheme://host[:port]) of a URL, or null when it
     * has none.
     */
    public static function canonicalOrigin(string $url): ?string
    {
        $parsed = parse_url(self::punycodeUrlHost($url));
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
        $parsed = parse_url(self::punycodeUrlHost($url));
        if ($parsed === false || ! isset($parsed['host'])) {
            return null;
        }

        return self::canonicalHostFromParts($parsed);
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
     * Punycode a non-ASCII host inside the raw URL string, before any
     * parse_url() call. Uses nontransitional UTS46 processing, matching the
     * WHATWG domain-to-ASCII operation (so "faß.de" becomes "xn--fa-hia.de",
     * never "fass.de", regardless of the environment's ICU defaults).
     * Without ext-intl, or when conversion fails, the URL is returned as-is.
     */
    private static function punycodeUrlHost(string $url): string
    {
        if (preg_match('/[^\x00-\x7F]/', $url) !== 1 || ! function_exists('idn_to_ascii')) {
            return $url;
        }

        $replaced = preg_replace_callback(
            '~^([a-z][a-z0-9+.\-]*://(?:[^/?#]*@)?)([^/?#:]+)~i',
            function (array $matches): string {
                if (preg_match('/[^\x00-\x7F]/', $matches[2]) !== 1) {
                    return $matches[0];
                }

                $ascii = idn_to_ascii($matches[2], IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);

                return $ascii === false ? $matches[0] : $matches[1] . $ascii;
            },
            $url,
        );

        return $replaced ?? $url;
    }

    /**
     * The canonical host from an already-parsed URL. The host must already be
     * ASCII (see punycodeUrlHost); it is lowercased and the default port for
     * the scheme is stripped.
     *
     * @param  array{scheme?: string, host: string, port?: int}  $parsed
     */
    private static function canonicalHostFromParts(array $parsed): string
    {
        $host = strtolower((string) $parsed['host']);
        $scheme = isset($parsed['scheme']) ? strtolower((string) $parsed['scheme']) : '';

        if (isset($parsed['port']) && ! self::isDefaultPort($scheme, (int) $parsed['port'])) {
            $host .= ':' . $parsed['port'];
        }

        return $host;
    }

    private static function isDefaultPort(string $scheme, int $port): bool
    {
        return ($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80);
    }
}
