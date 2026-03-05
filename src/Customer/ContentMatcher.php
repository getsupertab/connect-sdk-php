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
     *  3. Wildcard patterns (path ending in /*) match by prefix,
     *     with longer prefixes being more specific
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

            // Wildcard match: pattern ends with /*
            if (str_ends_with($patternPath, '/*')) {
                $prefix = substr($patternPath, 0, -1); // remove trailing *
                if (str_starts_with($path, $prefix)) {
                    $specificity = strlen($prefix);
                    if ($specificity > $bestSpecificity) {
                        $bestSpecificity = $specificity;
                        $bestMatch = $block;
                    }
                }
            }
        }

        if ($debug) {
            if ($bestMatch !== null) {
                error_log("[SupertabConnect] Wildcard match found: {$bestMatch->urlPattern} (specificity={$bestSpecificity})");
            } else {
                error_log("[SupertabConnect] No matching content block found for {$resourceUrl}");
            }
        }

        return $bestMatch;
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
