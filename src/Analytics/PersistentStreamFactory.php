<?php

declare(strict_types=1);

namespace Supertab\Connect\Analytics;

use RuntimeException;

/**
 * Opens a persistent socket to the relay via {@see stream_socket_client()} with
 * the STREAM_CLIENT_PERSISTENT flag, so the engine keeps the connection in a
 * per-worker pool and reuses it across requests (FPM, mod_php, FastCGI, and
 * long-running runtimes alike).
 *
 * If persistent streams are unavailable (e.g. disabled via `disable_functions`)
 * or the connection fails, open() throws and the surrounding
 * {@see FallbackConnection} switches to the cURL path.
 */
final class PersistentStreamFactory implements PersistentStreamFactoryInterface
{
    public function __construct(
        private readonly string $transport, // 'tls' or 'tcp'
        private readonly string $host,
        private readonly int $port,
        private readonly int $timeoutSeconds,
    ) {}

    public function open()
    {
        if (! function_exists('stream_socket_client')) {
            throw new RuntimeException('stream_socket_client is unavailable');
        }

        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'SNI_enabled' => true,
                'peer_name' => $this->host,
            ],
        ]);

        $remote = "{$this->transport}://{$this->host}:{$this->port}";
        $stream = @stream_socket_client(
            $remote,
            $errno,
            $errstr,
            (float) $this->timeoutSeconds,
            STREAM_CLIENT_CONNECT | STREAM_CLIENT_PERSISTENT,
            $context,
        );

        if ($stream === false) {
            throw new RuntimeException("Failed to connect to {$remote}: {$errstr} ({$errno})");
        }

        stream_set_timeout($stream, $this->timeoutSeconds);

        return $stream;
    }
}
