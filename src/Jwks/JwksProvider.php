<?php

declare(strict_types=1);

namespace Supertab\Connect\Jwks;

use Firebase\JWT\JWK;
use Firebase\JWT\Key;
use Supertab\Connect\Cache\CacheInterface;
use Supertab\Connect\Exception\HttpException;
use Supertab\Connect\Exception\JwksKeyNotFoundException;
use Supertab\Connect\Http\HttpClientInterface;

final class JwksProvider implements JwksProviderInterface
{
    private const CACHE_TTL_SECONDS = 48 * 60 * 60; // 48 hours

    private const JWKS_ENDPOINT_PATH = '/.well-known/jwks.json/platform';

    private readonly string $cacheKey;

    /** @var array<string, Key>|null */
    private ?array $cachedKeys = null;

    private ?int $cachedAt = null;

    public function __construct(
        private readonly string $baseUrl,
        private readonly HttpClientInterface $httpClient,
        private readonly bool $debug = false,
        private readonly ?CacheInterface $cache = null,
    ) {
        $this->cacheKey = 'supertab_jwks:' . md5($this->baseUrl);
    }

    /**
     * Get parsed JWKS keys, fetching from the API if not cached or expired.
     *
     * @return array<string, Key> Keyed by kid
     *
     * @throws HttpException
     */
    public function getKeys(bool $forceRefresh = false): array
    {
        // Fast path: in-memory cache (avoids re-parsing within same request)
        if (! $forceRefresh && $this->cachedKeys !== null && $this->cachedAt !== null) {
            if ((time() - $this->cachedAt) < self::CACHE_TTL_SECONDS) {
                return $this->cachedKeys;
            }
        }

        // Check external cache (e.g. WP transients, Redis)
        if (! $forceRefresh && $this->cache !== null) {
            $cachedJson = $this->cache->get($this->cacheKey);

            if ($cachedJson !== null) {
                if ($this->debug) {
                    error_log('[SupertabConnect] Using JWKS from external cache');
                }

                /** @var array{keys: array<int, array<string, mixed>>} $jwksData */
                $jwksData = json_decode($cachedJson, true, 512, JSON_THROW_ON_ERROR);

                $this->cachedKeys = JWK::parseKeySet($jwksData, 'ES256');
                $this->cachedAt = time();

                return $this->cachedKeys;
            }
        }

        $url = rtrim($this->baseUrl, '/') . self::JWKS_ENDPOINT_PATH;

        if ($this->debug) {
            error_log("[SupertabConnect] Fetching platform JWKS from: {$url}");
        }

        $response = $this->httpClient->get($url);

        if ($response['statusCode'] < 200 || $response['statusCode'] >= 300) {
            throw new HttpException(
                "Failed to fetch platform JWKS: HTTP {$response['statusCode']}",
                $response['statusCode'],
                $response['body'],
            );
        }

        // Store in external cache
        if ($this->cache !== null) {
            $this->cache->set($this->cacheKey, $response['body'], self::CACHE_TTL_SECONDS);

            if ($this->debug) {
                error_log('[SupertabConnect] Stored JWKS in external cache');
            }
        }

        /** @var array{keys: array<int, array<string, mixed>>} $jwksData */
        $jwksData = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);

        $this->cachedKeys = JWK::parseKeySet($jwksData, 'ES256');
        $this->cachedAt = time();

        return $this->cachedKeys;
    }

    /**
     * Find a specific key by kid, throwing JwksKeyNotFoundException if not found.
     *
     * @throws JwksKeyNotFoundException
     * @throws HttpException
     */
    public function getKeyByKid(string $kid, bool $forceRefresh = false): Key
    {
        $keys = $this->getKeys($forceRefresh);

        if (! isset($keys[$kid])) {
            throw new JwksKeyNotFoundException($kid);
        }

        return $keys[$kid];
    }

    public function clearCache(): void
    {
        $this->cachedKeys = null;
        $this->cachedAt = null;
    }
}
