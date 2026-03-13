<?php

declare(strict_types=1);

namespace Supertab\Connect\Cache;

interface CacheInterface
{
    /**
     * Get a cached value by key.
     *
     * @return string|null The cached value, or null if not found or expired
     */
    public function get(string $key): ?string;

    /**
     * Store a value in the cache.
     *
     * @param int $ttl Time-to-live in seconds
     */
    public function set(string $key, string $value, int $ttl): void;
}
