<?php

declare(strict_types=1);

namespace Supertab\Connect\Customer;

use Supertab\Connect\Exception\SupertabConnectException;
use Supertab\Connect\Http\HttpClientInterface;

final class LicenseTokenClient
{
    private const DEFAULT_SUPERTAB_BASE_URL = 'https://api-connect.supertab.co';

    private readonly TokenCache $cache;

    private readonly string $supertabBaseUrl;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly bool $debug = false,
        ?TokenCache $cache = null,
        string $supertabBaseUrl = self::DEFAULT_SUPERTAB_BASE_URL,
    ) {
        $this->cache = $cache ?? new TokenCache;
        $this->supertabBaseUrl = rtrim($supertabBaseUrl, '/');
    }

    /**
     * Obtain a license token for accessing a protected resource.
     *
     * Uses the OAuth2 client_credentials flow via the resource's license.xml,
     * on one of two lanes:
     *  - RSL License lane: a <content> block path-matches the resource, so the
     *    <license> chunk goes to that block's own URN-scoped {server}/token.
     *  - Agreement lane: nothing matches, so the chunk is omitted and the
     *    request goes license-less to the generic {supertabBaseUrl}/token,
     *    where the backend resolves the merchant system from the resource URL
     *    and the customer's single Active Agreement.
     *
     * @throws SupertabConnectException on any failure
     */
    public function obtainLicenseToken(
        string $clientId,
        string $clientSecret,
        string $resourceUrl,
    ): string {
        // 1. Fetch license.xml from the resource's origin
        $origin = $this->buildOrigin($resourceUrl);
        $xml = $this->fetchLicenseXml($origin);

        if ($this->debug) {
            error_log('[SupertabConnect] Fetched license.xml (' . strlen($xml) . ' chars)');
        }

        // 2. Parse and resolve the token endpoint
        $contentBlocks = LicenseXmlParser::parseContentElements($xml, $this->debug);

        if ($contentBlocks === []) {
            if ($this->debug) {
                error_log('[SupertabConnect] No valid <content> elements with <license> found in license.xml');
            }

            throw new SupertabConnectException(
                'No valid <content> elements with <license> found in license.xml'
            );
        }

        $endpoint = $this->selectTokenEndpoint($contentBlocks, $resourceUrl, $origin);

        // 3. Check cache. Keyed by server + scope: on the matched lane scope is
        // the block's urlPattern (token reuse across sibling paths); on the
        // Agreement lane scope is the origin (one Agreement token per origin).
        $cacheKey = "{$clientId}:{$endpoint->server}:{$endpoint->scope}";
        $cached = $this->cache->get($cacheKey, $this->debug);
        if ($cached !== null) {
            return $cached;
        }

        // 4. Request token
        $tokenEndpoint = rtrim($endpoint->server, '/') . '/token';

        if ($this->debug) {
            error_log("[SupertabConnect] Requesting license token from {$tokenEndpoint}");
        }

        $token = $this->requestToken(
            $tokenEndpoint,
            $clientId,
            $clientSecret,
            $endpoint->licenseXml,
            $resourceUrl,
        );

        // 5. Cache token
        $this->cacheToken($cacheKey, $token);

        return $token;
    }

    /**
     * Resolve where to mint, decoupled from whether the live license.xml still
     * grants the resource.
     *
     * A path-matching server-bearing <content> block gives the RSL License
     * lane: mint against that block's URN-scoped server with its <license>
     * chunk. Matches hosted on the configured Supertab API host are preferred
     * over matches from other providers, so a multi-provider license.xml never
     * routes credentials to a third party when a Supertab block also matches.
     * Anything else falls through to the Agreement lane, where the backend
     * resolves the merchant system from the resource URL and the customer's
     * single Active Agreement, so a diverged license.xml cannot veto the mint.
     *
     * @param  list<ContentBlock>  $contentBlocks
     */
    private function selectTokenEndpoint(array $contentBlocks, string $resourceUrl, string $origin): TokenEndpoint
    {
        $serverBlocks = array_values(array_filter($contentBlocks, fn (ContentBlock $b) => $b->server !== null));
        $supertabBlocks = array_values(array_filter($serverBlocks, fn (ContentBlock $b) => $this->isSupertabServer($b->server)));

        $matched = ContentMatcher::findBestMatch($supertabBlocks, $resourceUrl, $this->debug)
            ?? ContentMatcher::findBestMatch($serverBlocks, $resourceUrl, $this->debug);

        if ($matched !== null && $matched->server !== null) {
            if ($this->debug) {
                error_log("[SupertabConnect] Matched content block for resource URL: {$resourceUrl}");
            }

            return new TokenEndpoint(
                server: $matched->server,
                scope: $matched->urlPattern,
                matched: true,
                licenseXml: $matched->licenseXml,
            );
        }

        if ($this->debug) {
            $patterns = implode(', ', array_map(fn (ContentBlock $b) => $b->urlPattern, $serverBlocks));
            error_log(
                "[SupertabConnect] No <content> element matches resource URL: {$resourceUrl} (patterns: {$patterns}). "
                . "Minting license-less against {$this->supertabBaseUrl}; the backend resolves the Agreement."
            );
        }

        return new TokenEndpoint(server: $this->supertabBaseUrl, scope: $origin, matched: false);
    }

    /**
     * True when the server's host matches the configured Supertab API base host.
     */
    private function isSupertabServer(?string $server): bool
    {
        if ($server === null) {
            return false;
        }

        $serverHost = UrlCanonicalizer::canonicalHost($server);

        return $serverHost !== null && $serverHost === UrlCanonicalizer::canonicalHost($this->supertabBaseUrl);
    }

    /**
     * Derive the canonical origin from the resource URL.
     *
     * @throws SupertabConnectException when the URL has no origin
     */
    private function buildOrigin(string $resourceUrl): string
    {
        $origin = UrlCanonicalizer::canonicalOrigin($resourceUrl);
        if ($origin === null) {
            throw new SupertabConnectException("Invalid resource URL: {$resourceUrl}");
        }

        return $origin;
    }

    /**
     * Fetch license.xml from the given origin.
     *
     * @throws SupertabConnectException
     */
    private function fetchLicenseXml(string $origin): string
    {
        $licenseXmlUrl = $origin . '/license.xml';

        try {
            $response = $this->httpClient->get($licenseXmlUrl);
        } catch (\Throwable $e) {
            throw new SupertabConnectException(
                "Failed to fetch license.xml from {$licenseXmlUrl}: " . $e->getMessage(),
                0,
                $e,
            );
        }

        if ($response['statusCode'] < 200 || $response['statusCode'] >= 300) {
            if ($this->debug) {
                error_log("[SupertabConnect] Failed to fetch license.xml from {$licenseXmlUrl}: {$response['statusCode']}");
            }

            throw new SupertabConnectException(
                "Failed to fetch license.xml from {$licenseXmlUrl}: {$response['statusCode']}"
            );
        }

        if ($this->debug) {
            error_log("[SupertabConnect] Fetched license.xml from {$licenseXmlUrl}");
        }

        return $response['body'];
    }

    /**
     * Request a token from the token endpoint using client credentials.
     *
     * @throws SupertabConnectException
     */
    private function requestToken(
        string $tokenEndpoint,
        string $clientId,
        string $clientSecret,
        ?string $licenseXml,
        string $resource,
    ): string {
        $params = [
            'grant_type' => 'client_credentials',
            'resource' => $resource,
        ];

        // Only send the live <license> chunk when a public <content> block
        // actually matched; on the Agreement lane the backend mints from the
        // Agreement's pinned snapshot instead.
        if ($licenseXml !== null) {
            $params['license'] = $licenseXml;
        }

        $body = http_build_query($params);

        $headers = [
            'Content-Type' => 'application/x-www-form-urlencoded',
            'Accept' => 'application/json',
            'Authorization' => 'Basic ' . base64_encode("{$clientId}:{$clientSecret}"),
        ];

        try {
            $response = $this->httpClient->post($tokenEndpoint, $body, $headers);
        } catch (\Throwable $e) {
            throw new SupertabConnectException(
                'Failed to obtain license token: ' . $e->getMessage(),
                0,
                $e,
            );
        }

        if ($response['statusCode'] < 200 || $response['statusCode'] >= 300) {
            $errorBody = $response['body'] !== '' ? " - {$response['body']}" : '';

            throw new SupertabConnectException(
                "Failed to obtain license token: {$response['statusCode']}{$errorBody}"
            );
        }

        try {
            /** @var array{access_token?: string} $data */
            $data = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            if ($this->debug) {
                error_log('[SupertabConnect] Failed to parse license token response as JSON: ' . $e->getMessage());
            }

            throw new SupertabConnectException('Failed to parse license token response as JSON');
        }

        if (! isset($data['access_token']) || ! is_string($data['access_token'])) {
            throw new SupertabConnectException('License token response missing access_token');
        }

        return $data['access_token'];
    }

    /**
     * Decode the JWT payload to extract the exp claim and cache the token.
     */
    private function cacheToken(string $cacheKey, string $token): void
    {
        try {
            $segments = explode('.', $token);
            if (count($segments) !== 3) {
                return;
            }

            $payloadJson = $this->base64UrlDecode($segments[1]);
            if ($payloadJson === null) {
                return;
            }

            /** @var array{exp?: int} $payload */
            $payload = json_decode($payloadJson, true, 512, JSON_THROW_ON_ERROR);

            if (isset($payload['exp']) && is_int($payload['exp'])) {
                $this->cache->set($cacheKey, $token, $payload['exp']);
            }
        } catch (\Throwable) {
            if ($this->debug) {
                error_log('[SupertabConnect] Failed to decode token for caching, skipping cache');
            }
        }
    }

    private function base64UrlDecode(string $input): ?string
    {
        $remainder = strlen($input) % 4;
        if ($remainder !== 0) {
            $input .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode(strtr($input, '-_', '+/'), true);

        return $decoded !== false ? $decoded : null;
    }
}
