<?php

declare(strict_types=1);

namespace Supertab\Connect\Analytics;

/**
 * A reusable HTTP connection to a fixed endpoint.
 *
 * Implementations are expected to keep the underlying socket alive across
 * successive {@see self::post()} calls (HTTP keep-alive) so the TCP/TLS
 * handshake is paid once and amortized.
 */
interface KeepAliveConnectionInterface
{
    /**
     * POST a body to the connection's endpoint, reusing the underlying socket.
     *
     * @param  array<string, string>  $headers
     * @return int The HTTP status code.
     *
     * @throws \RuntimeException on transport failure (the caller swallows this to stay fail-open).
     */
    public function post(string $body, array $headers): int;
}
