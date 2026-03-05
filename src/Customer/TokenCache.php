<?php

declare(strict_types=1);

namespace Supertab\Connect\Customer;

final class TokenCache
{
    private const EXPIRY_BUFFER_SECONDS = 30;

    /** @var array<string, array{token: string, exp: int}> */
    private array $cache = [];

    /**
     * Get a cached token if it exists and hasn't expired (with 30s buffer).
     */
    public function get(string $key, bool $debug = false): ?string
    {
        if (! isset($this->cache[$key])) {
            return null;
        }

        $entry = $this->cache[$key];
        $now = time();

        if ($entry['exp'] > $now + self::EXPIRY_BUFFER_SECONDS) {
            if ($debug) {
                $expiresIn = $entry['exp'] - $now;
                error_log("[SupertabConnect] Using cached license token (expires in {$expiresIn}s)");
            }

            return $entry['token'];
        }

        if ($debug) {
            error_log('[SupertabConnect] Cached license token expired or expiring soon, refreshing');
        }

        unset($this->cache[$key]);

        return null;
    }

    /**
     * Store a token in the cache.
     */
    public function set(string $key, string $token, int $exp): void
    {
        $this->cache[$key] = ['token' => $token, 'exp' => $exp];
    }

    /**
     * Clear all cached tokens.
     */
    public function clear(): void
    {
        $this->cache = [];
    }
}
