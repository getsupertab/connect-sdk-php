<?php

declare(strict_types=1);

namespace Supertab\Connect\Tests\Jwks;

use PHPUnit\Framework\TestCase;
use Supertab\Connect\Cache\CacheInterface;
use Supertab\Connect\Exception\HttpException;
use Supertab\Connect\Http\HttpClientInterface;
use Supertab\Connect\Jwks\JwksProvider;

final class JwksProviderTest extends TestCase
{
    private const BASE_URL = 'https://api-connect.supertab.co';

    private static function jwksJson(): string
    {
        return json_encode([
            'keys' => [[
                'kty' => 'EC',
                'crv' => 'P-256',
                'kid' => 'test-kid',
                'x' => 'f83OJ3D2xF1Bg8vub9tLe1gHMzV76e8Tus9uPHvRVEU',
                'y' => 'x_FEzRu9m36HLN_tue659LNpXW6pCyStikYjKIWI5a0',
                'use' => 'sig',
                'alg' => 'ES256',
            ]],
        ]);
    }

    public function test_fetches_from_http_when_no_cache(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects($this->once())
            ->method('get')
            ->willReturn(['statusCode' => 200, 'body' => self::jwksJson()]);

        $provider = new JwksProvider(self::BASE_URL, $httpClient);
        $keys = $provider->getKeys();

        $this->assertArrayHasKey('test-kid', $keys);
    }

    public function test_uses_external_cache_on_hit(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->expects($this->once())
            ->method('get')
            ->willReturn(self::jwksJson());

        // HTTP should never be called when external cache hits
        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects($this->never())->method('get');

        $provider = new JwksProvider(self::BASE_URL, $httpClient, cache: $cache);
        $keys = $provider->getKeys();

        $this->assertArrayHasKey('test-kid', $keys);
    }

    public function test_stores_in_external_cache_after_http_fetch(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        // Cache miss
        $cache->method('get')->willReturn(null);
        // Should store the fetched JSON
        $cache->expects($this->once())
            ->method('set')
            ->with(
                $this->anything(),
                self::jwksJson(),
                48 * 60 * 60,
            );

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->method('get')
            ->willReturn(['statusCode' => 200, 'body' => self::jwksJson()]);

        $provider = new JwksProvider(self::BASE_URL, $httpClient, cache: $cache);
        $provider->getKeys();
    }

    public function test_force_refresh_bypasses_external_cache(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        // get() should not be called on force refresh
        $cache->expects($this->never())->method('get');
        // But set() should be called to update the cache
        $cache->expects($this->once())->method('set');

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects($this->once())
            ->method('get')
            ->willReturn(['statusCode' => 200, 'body' => self::jwksJson()]);

        $provider = new JwksProvider(self::BASE_URL, $httpClient, cache: $cache);
        $keys = $provider->getKeys(forceRefresh: true);

        $this->assertArrayHasKey('test-kid', $keys);
    }

    public function test_in_memory_cache_avoids_repeated_external_cache_reads(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        // External cache should only be hit once (first call); second call uses in-memory
        $cache->expects($this->once())
            ->method('get')
            ->willReturn(self::jwksJson());

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects($this->never())->method('get');

        $provider = new JwksProvider(self::BASE_URL, $httpClient, cache: $cache);
        $provider->getKeys();
        $provider->getKeys(); // Should use in-memory cache
    }

    public function test_falls_back_to_http_when_external_cache_misses(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('get')->willReturn(null);
        $cache->expects($this->once())->method('set');

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects($this->once())
            ->method('get')
            ->willReturn(['statusCode' => 200, 'body' => self::jwksJson()]);

        $provider = new JwksProvider(self::BASE_URL, $httpClient, cache: $cache);
        $keys = $provider->getKeys();

        $this->assertArrayHasKey('test-kid', $keys);
    }

    public function test_throws_on_http_failure(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->method('get')
            ->willReturn(['statusCode' => 500, 'body' => 'Internal Server Error']);

        $provider = new JwksProvider(self::BASE_URL, $httpClient);

        $this->expectException(HttpException::class);
        $provider->getKeys();
    }
}
