<?php

declare(strict_types=1);

namespace Supertab\Connect\Customer;

final class ContentMatcher
{
    /**
     * Find the best matching content block for the given resource URL.
     *
     * Matching rules:
     *  1. Host (including port) must match exactly
     *  2. Exact path match returns immediately (highest priority)
     *  3. Robots.txt-style pattern matching via scorePathPattern():
     *     - `*` matches zero or more characters (including `/`)
     *     - Trailing `$` anchors the match to end of path
     *     - Without `$` or `*`, matches at segment boundaries
     *     - Specificity = number of literal (non-wildcard) characters
     *  4. Returns null if no block matches
     *
     * @param  list<ContentBlock>  $contentBlocks
     */
    public static function findBestMatch(array $contentBlocks, string $resourceUrl, bool $debug = false): ?ContentBlock
    {
        $parsed = parse_url($resourceUrl);
        if ($parsed === false || ! isset($parsed['host'])) {
            if ($debug) {
                error_log("[SupertabConnect] Cannot parse resource URL: {$resourceUrl}");
            }

            return null;
        }

        $host = self::extractHost($parsed);
        $path = $parsed['path'] ?? '/';

        if ($debug) {
            error_log("[SupertabConnect] Matching resource URL: {$resourceUrl} (host={$host}, path={$path})");
        }

        $bestMatch = null;
        $bestSpecificity = -1;

        foreach ($contentBlocks as $block) {
            $patternParsed = parse_url($block->urlPattern);
            if ($patternParsed === false || ! isset($patternParsed['host'])) {
                if ($debug) {
                    error_log("[SupertabConnect] Skipping block with invalid URL pattern: {$block->urlPattern}");
                }

                continue;
            }

            $patternHost = self::extractHost($patternParsed);

            if ($patternHost !== $host) {
                if ($debug) {
                    error_log("[SupertabConnect] Skipping block: host mismatch (pattern={$patternHost}, resource={$host})");
                }

                continue;
            }

            $patternPath = $patternParsed['path'] ?? '/';

            // Exact match takes highest priority
            if ($patternPath === $path) {
                if ($debug) {
                    error_log("[SupertabConnect] Exact match found: {$block->urlPattern}");
                }

                return $block;
            }

            // Pattern match (wildcards, prefix, anchored)
            $specificity = self::scorePathPattern($patternPath, $path);
            if ($specificity > $bestSpecificity) {
                $bestSpecificity = $specificity;
                $bestMatch = $block;
            }
        }

        if ($debug) {
            if ($bestMatch !== null) {
                error_log("[SupertabConnect] Pattern match found: {$bestMatch->urlPattern} (specificity={$bestSpecificity})");
            } else {
                error_log("[SupertabConnect] No matching content block found for {$resourceUrl}");
            }
        }

        return $bestMatch;
    }

    /**
     * Score a path against a robots.txt-style pattern.
     *
     * - `*` matches zero or more characters (including `/`)
     * - Trailing `$` anchors the match to the end of the path
     * - Without `$`, patterns without `*` match as prefix at segment boundaries
     *   (e.g., `/content` matches `/content/article` but not `/content-other`)
     * - Without `$`, patterns with `*` are prefix-matched from the start
     *
     * Returns specificity (number of literal characters) on match, or -1 on no match.
     */
    public static function scorePathPattern(string $pattern, string $path): int
    {
        $anchored = false;
        $pat = $pattern;

        if (str_ends_with($pat, '$')) {
            $anchored = true;
            $pat = substr($pat, 0, -1);
        }

        $hasWildcard = str_contains($pat, '*');

        // Escape regex special characters, then convert \* back to .*
        $escaped = preg_quote($pat, '/');
        $regexBody = str_replace('\\*', '.*', $escaped);

        if ($anchored) {
            $regexStr = '/^' . $regexBody . '$/';
        } elseif ($hasWildcard) {
            $regexStr = '/^' . $regexBody . '/';
        } else {
            // No wildcards, no anchor: prefix match at segment boundary
            if ($pat === '/') {
                $regexStr = '/^\\//';
            } else {
                $regexStr = '/^' . $regexBody . '(\\/|$)/';
            }
        }

        if (preg_match($regexStr, $path) === 1) {
            return strlen(str_replace('*', '', $pat));
        }

        return -1;
    }

    /**
     * Extract the host string including port.
     *
     * @param  array<string, mixed>  $parsed
     */
    private static function extractHost(array $parsed): string
    {
        $host = (string) $parsed['host'];
        if (isset($parsed['port'])) {
            $host .= ':' . $parsed['port'];
        }

        return $host;
    }
}
