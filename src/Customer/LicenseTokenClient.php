<?php

declare(strict_types=1);

namespace Supertab\Connect\Customer;

use Firebase\JWT\JWT;
use Supertab\Connect\Exception\SupertabConnectException;
use Supertab\Connect\Http\HttpClientInterface;

final class LicenseTokenClient
{
    private readonly TokenCache $cache;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly bool $debug = false,
        ?TokenCache $cache = null,
    ) {
        $this->cache = $cache ?? new TokenCache;
    }

    /**
     * Obtain a license token for accessing a protected resource.
     *
     * Uses the OAuth2 client_credentials flow via the resource's license.xml.
     *
     * @throws SupertabConnectException on any failure
     */
    public function obtainLicenseToken(
        string $clientId,
        string $clientSecret,
        string $resourceUrl,
    ): string {
        // 1. Check cache
        $cacheKey = "{$clientId}:{$resourceUrl}";
        $cached = $this->cache->get($cacheKey, $this->debug);
        if ($cached !== null) {
            return $cached;
        }

        // 2. Fetch license.xml
        $xml = $this->fetchLicenseXml($resourceUrl);

        if ($this->debug) {
            error_log('[SupertabConnect] Fetched license.xml (' . strlen($xml) . ' chars)');
        }

        // 3. Parse and match
        $matchedContent = $this->resolveContentMatch($xml, $resourceUrl);

        // 4. Request token
        $tokenEndpoint = rtrim($matchedContent->server, '/') . '/token';

        if ($this->debug) {
            error_log("[SupertabConnect] Requesting license token from {$tokenEndpoint}");
        }

        $token = $this->requestToken(
            $tokenEndpoint,
            $clientId,
            $clientSecret,
            $matchedContent->licenseXml,
            $matchedContent->urlPattern,
        );

        // 5. Cache token
        $this->cacheToken($cacheKey, $token);

        return $token;
    }

    /**
     * Generate a license token using private key JWT assertion.
     *
     * The caller provides the license XML content (typically fetched from the
     * publisher's license.xml endpoint). The SDK parses it, matches the resource
     * URL, and requests a token using a signed JWT client assertion.
     *
     * @throws SupertabConnectException on any failure
     */
    public function generateLicenseToken(
        string $clientId,
        string $kid,
        string $privateKeyPem,
        string $resourceUrl,
        string $licenseXml,
    ): string {
        // 1. Check cache
        $cacheKey = "{$clientId}:{$resourceUrl}";
        $cached = $this->cache->get($cacheKey, $this->debug);
        if ($cached !== null) {
            return $cached;
        }

        // 2. Parse and match
        $matchedContent = $this->resolveContentMatch($licenseXml, $resourceUrl);

        // 3. Build token endpoint
        $tokenEndpoint = rtrim($matchedContent->server, '/') . '/token';

        if ($this->debug) {
            error_log("[SupertabConnect] Requesting license token from {$tokenEndpoint} using JWT assertion");
        }

        // 4. Detect key algorithm and create JWT assertion
        $alg = self::detectKeyAlgorithm($privateKeyPem);

        if ($this->debug) {
            error_log("[SupertabConnect] Detected key algorithm: {$alg}");
        }

        $now = time();
        $payload = [
            'iss' => $clientId,
            'sub' => $clientId,
            'aud' => $tokenEndpoint,
            'iat' => $now,
            'exp' => $now + 300,
        ];

        $clientAssertion = JWT::encode($payload, $privateKeyPem, $alg, $kid);

        // 5. POST to token endpoint
        $body = http_build_query([
            'grant_type' => 'rsl',
            'client_assertion_type' => 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer',
            'client_assertion' => $clientAssertion,
            'license' => $matchedContent->licenseXml,
            'resource' => $matchedContent->urlPattern,
        ]);

        $headers = [
            'Content-Type' => 'application/x-www-form-urlencoded',
            'Accept' => 'application/json',
        ];

        $response = $this->postToTokenEndpoint($tokenEndpoint, $body, $headers);
        $token = $this->parseTokenResponse($response);

        // 6. Cache token
        $this->cacheToken($cacheKey, $token);

        return $token;
    }

    /**
     * Parse license XML and find the best matching content block for a resource URL.
     *
     * @throws SupertabConnectException
     */
    private function resolveContentMatch(string $licenseXml, string $resourceUrl): ContentBlock
    {
        $contentBlocks = LicenseXmlParser::parseContentElements($licenseXml, $this->debug);

        if ($contentBlocks === []) {
            if ($this->debug) {
                error_log('[SupertabConnect] No valid <content> elements with <license> found in license.xml');
            }

            throw new SupertabConnectException(
                'No valid <content> elements with <license> found in license.xml'
            );
        }

        $matchedContent = ContentMatcher::findBestMatch($contentBlocks, $resourceUrl, $this->debug);

        if ($matchedContent === null) {
            if ($this->debug) {
                $patterns = implode(', ', array_map(fn (ContentBlock $b) => $b->urlPattern, $contentBlocks));
                error_log("[SupertabConnect] No <content> element matches resource URL: {$resourceUrl}. Available patterns: {$patterns}");
            }

            throw new SupertabConnectException(
                "No <content> element in license.xml matches resource URL: {$resourceUrl}"
            );
        }

        if ($this->debug) {
            error_log("[SupertabConnect] Matched content block for resource URL: {$resourceUrl}");
            error_log("[SupertabConnect] Using license XML: {$matchedContent->licenseXml}");
        }

        return $matchedContent;
    }

    /**
     * Detect the JWT signing algorithm from a PEM-encoded private key.
     *
     * @throws SupertabConnectException if the key format is unsupported
     */
    private static function detectKeyAlgorithm(string $privateKeyPem): string
    {
        $key = openssl_pkey_get_private($privateKeyPem);
        if ($key === false) {
            throw new SupertabConnectException(
                'Unsupported private key format. Expected RSA or P-256 EC private key.'
            );
        }

        $details = openssl_pkey_get_details($key);
        if ($details === false) {
            throw new SupertabConnectException(
                'Unsupported private key format. Expected RSA or P-256 EC private key.'
            );
        }

        if ($details['type'] === OPENSSL_KEYTYPE_EC) {
            $curveName = $details['ec']['curve_name'] ?? '';
            if ($curveName !== 'prime256v1') {
                throw new SupertabConnectException(
                    "Unsupported EC curve: {$curveName}. Expected prime256v1 (P-256)."
                );
            }

            return 'ES256';
        }

        if ($details['type'] === OPENSSL_KEYTYPE_RSA) {
            return 'RS256';
        }

        throw new SupertabConnectException(
            'Unsupported private key format. Expected RSA or P-256 EC private key.'
        );
    }

    /**
     * Fetch license.xml from the resource URL's origin.
     *
     * @throws SupertabConnectException
     */
    private function fetchLicenseXml(string $resourceUrl): string
    {
        $parsed = parse_url($resourceUrl);
        if ($parsed === false || ! isset($parsed['scheme'], $parsed['host'])) {
            throw new SupertabConnectException("Invalid resource URL: {$resourceUrl}");
        }

        $origin = $parsed['scheme'] . '://' . $parsed['host'];
        if (isset($parsed['port'])) {
            $origin .= ':' . $parsed['port'];
        }

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
        string $licenseXml,
        string $resource,
    ): string {
        $body = http_build_query([
            'grant_type' => 'client_credentials',
            'license' => $licenseXml,
            'resource' => $resource,
        ]);

        $headers = [
            'Content-Type' => 'application/x-www-form-urlencoded',
            'Accept' => 'application/json',
            'Authorization' => 'Basic ' . base64_encode("{$clientId}:{$clientSecret}"),
        ];

        $response = $this->postToTokenEndpoint($tokenEndpoint, $body, $headers);

        return $this->parseTokenResponse($response);
    }

    /**
     * POST to a token endpoint with error wrapping.
     *
     * @param  array<string, string>  $headers
     * @return array{statusCode: int, body: string}
     *
     * @throws SupertabConnectException
     */
    private function postToTokenEndpoint(string $tokenEndpoint, string $body, array $headers): array
    {
        try {
            return $this->httpClient->post($tokenEndpoint, $body, $headers);
        } catch (\Throwable $e) {
            throw new SupertabConnectException(
                'Failed to obtain license token: ' . $e->getMessage(),
                0,
                $e,
            );
        }
    }

    /**
     * Parse a token endpoint response and extract the access_token.
     *
     * @param  array{statusCode: int, body: string}  $response
     *
     * @throws SupertabConnectException
     */
    private function parseTokenResponse(array $response): string
    {
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
