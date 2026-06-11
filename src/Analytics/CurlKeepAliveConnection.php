<?php

declare(strict_types=1);

namespace Supertab\Connect\Analytics;

use CurlHandle;
use RuntimeException;
use Supertab\Connect\Http\HttpClient;

/**
 * A {@see KeepAliveConnectionInterface} backed by a single, reused cURL handle.
 *
 * Reusing one handle lets cURL keep the TCP/TLS connection in its connection
 * cache and reuse it across {@see self::post()} calls, so the handshake is paid
 * once. cURL also transparently re-establishes the connection if the cached one
 * has gone stale, so callers do not need their own reconnect logic.
 *
 * Lifetime note: the handle lives for the lifetime of this object. In a
 * long-running runtime (Swoole, RoadRunner, Laravel Octane) that spans many
 * requests, the connection is reused across all of them. Under classic
 * PHP-FPM/CGI — where userland state is torn down after each request — reuse is
 * limited to a single request; cross-request reuse there would require
 * persistent sockets, which this class intentionally does not attempt.
 */
final class CurlKeepAliveConnection implements KeepAliveConnectionInterface
{
    private ?CurlHandle $handle = null;

    public function __construct(
        private readonly string $url,
        private readonly int $timeoutSeconds,
    ) {}

    public function post(string $body, array $headers): int
    {
        $handle = $this->handle ??= $this->initHandle();

        curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        curl_setopt($handle, CURLOPT_HTTPHEADER, $this->formatHeaders($headers));

        $result = curl_exec($handle);

        if ($result === false || curl_errno($handle) !== 0) {
            $message = curl_error($handle);
            $errno = curl_errno($handle);
            // Drop the possibly-poisoned handle so the next call starts clean.
            $this->close();

            throw new RuntimeException("cURL error: {$message}", $errno);
        }

        return (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
    }

    public function close(): void
    {
        // Dropping the last reference frees the handle and closes the connection.
        // (curl_close() has been a no-op since PHP 8.0 and is deprecated in 8.5.)
        $this->handle = null;
    }

    public function __destruct()
    {
        $this->close();
    }

    private function initHandle(): CurlHandle
    {
        $handle = curl_init();

        curl_setopt_array($handle, [
            CURLOPT_URL => $this->url,
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => $this->timeoutSeconds,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            // Keep the connection alive and reusable between calls.
            CURLOPT_TCP_KEEPALIVE => 1,
            CURLOPT_FORBID_REUSE => false,
            CURLOPT_USERAGENT => HttpClient::resolveUserAgent(),
        ]);

        return $handle;
    }

    /**
     * @param  array<string, string>  $headers
     * @return list<string>
     */
    private function formatHeaders(array $headers): array
    {
        $formatted = [];
        foreach ($headers as $name => $value) {
            $formatted[] = "{$name}: {$value}";
        }

        return $formatted;
    }
}
