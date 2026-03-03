<?php

declare(strict_types=1);

namespace Supertab\Connect\Jwks;

use Firebase\JWT\Key;
use Supertab\Connect\Exception\JwksKeyNotFoundException;
use Supertab\Connect\Exception\HttpException;

interface JwksProviderInterface
{
    /**
     * Get parsed JWKS keys, fetching from the API if not cached or expired.
     *
     * @return array<string, Key> Keyed by kid
     *
     * @throws HttpException
     */
    public function getKeys(bool $forceRefresh = false): array;

    /**
     * Find a specific key by kid.
     *
     * @throws JwksKeyNotFoundException
     * @throws HttpException
     */
    public function getKeyByKid(string $kid, bool $forceRefresh = false): Key;

    public function clearCache(): void;
}
