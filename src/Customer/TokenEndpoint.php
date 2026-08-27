<?php

declare(strict_types=1);

namespace Supertab\Connect\Customer;

/**
 * Where to mint a license token.
 *
 * `matched` picks the RSL License lane (a <content> block path-matched: mint
 * against its URN-scoped server with its <license> chunk) over the Agreement
 * lane (nothing matched: mint license-less against the generic Supertab API
 * base, where the backend resolves the customer's Active Agreement).
 */
final class TokenEndpoint
{
    public function __construct(
        public readonly string $server,
        public readonly string $scope,
        public readonly bool $matched,
        public readonly ?string $licenseXml = null,
    ) {}
}
