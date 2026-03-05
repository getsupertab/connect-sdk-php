<?php

declare(strict_types=1);

namespace Supertab\Connect\Tests\Customer;

use PHPUnit\Framework\TestCase;
use Supertab\Connect\Customer\TokenCache;

final class TokenCacheTest extends TestCase
{
    private TokenCache $cache;

    protected function setUp(): void
    {
        $this->cache = new TokenCache;
    }

    public function test_returns_null_for_unknown_key(): void
    {
        $this->assertNull($this->cache->get('unknown-key'));
    }

    public function test_stores_and_retrieves_token(): void
    {
        $this->cache->set('key', 'my-token', time() + 3600);

        $this->assertSame('my-token', $this->cache->get('key'));
    }

    public function test_returns_null_for_expired_token(): void
    {
        $this->cache->set('key', 'expired-token', time() - 60);

        $this->assertNull($this->cache->get('key'));
    }

    public function test_returns_null_when_within_buffer(): void
    {
        // Token expires in 20 seconds — within the 30-second buffer
        $this->cache->set('key', 'expiring-token', time() + 20);

        $this->assertNull($this->cache->get('key'));
    }

    public function test_returns_token_when_outside_buffer(): void
    {
        // Token expires in 60 seconds — outside the 30-second buffer
        $this->cache->set('key', 'valid-token', time() + 60);

        $this->assertSame('valid-token', $this->cache->get('key'));
    }

    public function test_clear_removes_all_entries(): void
    {
        $this->cache->set('key1', 'token1', time() + 3600);
        $this->cache->set('key2', 'token2', time() + 3600);

        $this->cache->clear();

        $this->assertNull($this->cache->get('key1'));
        $this->assertNull($this->cache->get('key2'));
    }
}
