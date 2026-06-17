<?php

declare(strict_types=1);

namespace Supertab\Connect\Analytics;

/**
 * Analytics transport that POSTs events to the relay over a reused,
 * keep-alive connection (see {@see CurlKeepAliveConnection}).
 *
 * Behaves identically to {@see HttpAnalyticsTransport} — same endpoint, Bearer
 * auth, and fail-open semantics — but amortizes the TCP/TLS handshake across
 * emits instead of opening a fresh connection each time.
 */
final class KeepAliveHttpAnalyticsTransport implements AnalyticsTransportInterface
{
    public function __construct(
        private readonly string $apiKey,
        private readonly KeepAliveConnectionInterface $connection,
        private readonly bool $debug = false,
    ) {}

    /**
     * Build the adaptive transport: a persistent-socket connection (reused across
     * requests on FPM, mod_php, FastCGI, and long-running runtimes) that falls
     * back to a reused cURL connection on any failure or unsupported platform.
     */
    public static function adaptive(string $apiKey, string $baseUrl, int $timeoutSeconds, bool $debug = false): self
    {
        $url = rtrim($baseUrl, '/') . self::ANALYTICS_EVENTS_PATH;
        ['transport' => $transport, 'host' => $host, 'port' => $port, 'path' => $path] = self::parseTarget($url);

        $persistent = new PersistentSocketConnection(
            new PersistentStreamFactory($transport, $host, $port, $timeoutSeconds),
            $host,
            $path,
        );
        $fallback = new CurlKeepAliveConnection($url, $timeoutSeconds);

        return new self($apiKey, new FallbackConnection($persistent, $fallback), $debug);
    }

    /**
     * Parse a relay URL into socket-connection parts.
     *
     * @internal
     *
     * @return array{transport: string, host: string, port: int, path: string}
     */
    public static function parseTarget(string $url): array
    {
        $parts = parse_url($url);
        $scheme = $parts['scheme'] ?? 'https';
        $path = $parts['path'] ?? '/';
        if (isset($parts['query'])) {
            $path .= '?' . $parts['query'];
        }

        return [
            'transport' => $scheme === 'https' ? 'tls' : 'tcp',
            'host' => $parts['host'] ?? '',
            'port' => $parts['port'] ?? ($scheme === 'https' ? 443 : 80),
            'path' => $path,
        ];
    }

    public function emit(AnalyticsEvent $event): void
    {
        try {
            $status = $this->connection->post(
                json_encode($event->toArray(), JSON_THROW_ON_ERROR),
                [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type' => 'application/json',
                ],
            );

            if ($this->debug && ($status < 200 || $status >= 300)) {
                error_log('[SupertabConnect] Failed to emit analytics event: ' . $status);
            }
        } catch (\Throwable $e) {
            if ($this->debug) {
                error_log('[SupertabConnect] Error emitting analytics event: ' . $e->getMessage());
            }
        }
    }
}
