<?php

declare(strict_types=1);

namespace Supertab\Connect\Jwks;

use Firebase\JWT\JWK;
use Firebase\JWT\Key;
use Supertab\Connect\Exception\HttpException;
use Supertab\Connect\Exception\JwksKeyNotFoundException;
use Supertab\Connect\Http\HttpClientInterface;

final class JwksProvider implements JwksProviderInterface
{
    private const CACHE_TTL_SECONDS = 48 * 60 * 60; // 48 hours

    private const JWKS_ENDPOINT_PATH = '/.well-known/jwks.json/platform';

    /** @var array<string, Key>|null */
    private ?array $cachedKeys = null;

    private ?int $cachedAt = null;

    public function __construct(
        private readonly string $baseUrl,
        private readonly HttpClientInterface $httpClient,
        private readonly bool $debug = false,
    ) {}

    /**
     * Get parsed JWKS keys, fetching from the API if not cached or expired.
     *
     * @return array<string, Key> Keyed by kid
     *
     * @throws HttpException
     */
    public function getKeys(bool $forceRefresh = false): array
    {
        if (! $forceRefresh && $this->cachedKeys !== null && $this->cachedAt !== null) {
            if ((time() - $this->cachedAt) < self::CACHE_TTL_SECONDS) {
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
