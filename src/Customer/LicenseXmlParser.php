<?php

declare(strict_types=1);

namespace Supertab\Connect\Customer;

final class LicenseXmlParser
{
    /**
     * Parse <content> elements from license.xml text.
     *
     * Each valid <content> element must have:
     *  - A `url` attribute (the URL pattern)
     *  - A `server` attribute (the token endpoint base)
     *  - A nested `<license>` element (preserved as raw XML)
     *
     * @return list<ContentBlock>
     */
    public static function parseContentElements(string $xml, bool $debug = false): array
    {
        $previousUseErrors = libxml_use_internal_errors(true);

        try {
            $sxml = new \SimpleXMLElement($xml);
        } catch (\Exception $e) {
            libxml_clear_errors();
            libxml_use_internal_errors($previousUseErrors);
            if ($debug) {
                error_log('[SupertabConnect] Failed to parse license.xml: ' . $e->getMessage());
            }

            return [];
        }

        libxml_clear_errors();
        libxml_use_internal_errors($previousUseErrors);

        // Try namespaced query first, then fall back to non-namespaced
        $sxml->registerXPathNamespace('rsl', 'https://rslstandard.org/rsl');
        $contentElements = $sxml->xpath('//rsl:content');

        if ($contentElements === false || $contentElements === []) {
            $contentElements = $sxml->xpath('//content');
        }

        if ($contentElements === false || $contentElements === []) {
            if ($debug) {
                error_log('[SupertabConnect] Found 0 <content> element(s), 0 valid');
            }

            return [];
        }

        $contentBlocks = [];
        $elementCount = 0;

        foreach ($contentElements as $element) {
            $elementCount++;

            $url = isset($element['url']) ? (string) $element['url'] : null;
            $server = isset($element['server']) ? (string) $element['server'] : null;

            // Find nested <license> element (try namespaced first, then non-namespaced)
            $licenseNode = $element->children('https://rslstandard.org/rsl')->license;
            if ($licenseNode === null || count($licenseNode) === 0) {
                $licenseNode = $element->license;
            }

            $licenseXml = ($licenseNode !== null && count($licenseNode) > 0)
                ? $licenseNode->asXML()
                : null;

            if ($url !== null && $url !== '' && $server !== null && $server !== '' && $licenseXml !== null && $licenseXml !== false) {
                $contentBlocks[] = new ContentBlock(
                    urlPattern: $url,
                    server: $server,
                    licenseXml: $licenseXml,
                );
            } elseif ($debug) {
                $missing = array_filter([
                    ($url === null || $url === '') ? 'url' : null,
                    ($server === null || $server === '') ? 'server' : null,
                    ($licenseXml === null || $licenseXml === false) ? '<license>' : null,
                ]);
                error_log("[SupertabConnect] Skipping <content> element #{$elementCount}: missing " . implode(', ', $missing));
            }
        }

        if ($debug) {
            error_log("[SupertabConnect] Found {$elementCount} <content> element(s), " . count($contentBlocks) . ' valid');
        }

        return $contentBlocks;
    }
}
