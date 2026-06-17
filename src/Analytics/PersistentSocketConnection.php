<?php

declare(strict_types=1);

namespace Supertab\Connect\Analytics;

use RuntimeException;
use Supertab\Connect\Http\HttpClient;

/**
 * A {@see KeepAliveConnectionInterface} that speaks HTTP/1.1 over a persistent
 * socket, so the connection is reused across requests within the same
 * worker/process (the only mechanism that reuses connections across PHP-FPM
 * requests — see {@see PersistentStreamFactoryInterface}).
 *
 * Robustness rules:
 *  - Each response is fully drained (Content-Length or chunked). A connection is
 *    only kept for reuse when it is left at a clean message boundary; on any
 *    anomaly (parse error, `Connection: close`, undelimited body) the socket is
 *    closed so it is never reused dirty.
 *  - A pooled connection may have been closed by the server while idle, so a
 *    failed attempt reconnects once before giving up.
 *  - On unrecoverable failure it throws; callers wrap it in a
 *    {@see FallbackConnection} so emission stays fail-open.
 *
 * The object never closes the socket in its destructor: the persistent stream
 * is meant to outlive the object and stay in PHP's connection pool for the next
 * request to reuse.
 */
final class PersistentSocketConnection implements KeepAliveConnectionInterface
{
    /** @var resource|null */
    private $stream = null;

    public function __construct(
        private readonly PersistentStreamFactoryInterface $factory,
        private readonly string $host,
        private readonly string $path,
    ) {}

    public function post(string $body, array $headers): int
    {
        try {
            return $this->attempt($body, $headers);
        } catch (\Throwable) {
            // The pooled socket may have been closed by the server while idle —
            // drop it and try once more with a fresh connection.
            $this->closeStream();

            return $this->attempt($body, $headers);
        }
    }

    private function attempt(string $body, array $headers): int
    {
        $stream = $this->stream ??= $this->factory->open();

        $this->writeRequest($stream, $body, $headers);
        [$status, $reusable] = $this->readResponse($stream);

        if (! $reusable) {
            $this->closeStream();
        }

        return $status;
    }

    /**
     * @param  resource  $stream
     * @param  array<string, string>  $headers
     */
    private function writeRequest($stream, string $body, array $headers): void
    {
        $lines = [
            "POST {$this->path} HTTP/1.1",
            "Host: {$this->host}",
        ];
        foreach ($headers as $name => $value) {
            $lines[] = "{$name}: {$value}";
        }
        $lines[] = 'User-Agent: ' . HttpClient::resolveUserAgent();
        $lines[] = 'Content-Length: ' . strlen($body);
        $lines[] = 'Connection: keep-alive';

        $request = implode("\r\n", $lines) . "\r\n\r\n" . $body;

        $total = strlen($request);
        $written = 0;
        while ($written < $total) {
            $bytes = @fwrite($stream, substr($request, $written));
            if ($bytes === false || $bytes === 0) {
                throw new RuntimeException('Failed to write analytics request to socket');
            }
            $written += $bytes;
        }
    }

    /**
     * @param  resource  $stream
     * @return array{0: int, 1: bool} status code, and whether the socket is reusable
     */
    private function readResponse($stream): array
    {
        $statusLine = $this->readLine($stream);
        if ($statusLine === false) {
            throw new RuntimeException('Empty analytics response (connection closed)');
        }
        $status = $this->parseStatus($statusLine);

        $headers = [];
        while (true) {
            $line = $this->readLine($stream);
            if ($line === false) {
                throw new RuntimeException('Unexpected EOF reading analytics response headers');
            }
            if ($line === '') {
                break;
            }
            $colon = strpos($line, ':');
            if ($colon !== false) {
                $headers[strtolower(trim(substr($line, 0, $colon)))] = trim(substr($line, $colon + 1));
            }
        }

        $reusable = ! (isset($headers['connection']) && strtolower($headers['connection']) === 'close');

        if (isset($headers['transfer-encoding']) && stripos($headers['transfer-encoding'], 'chunked') !== false) {
            $this->drainChunked($stream);

            return [$status, $reusable];
        }

        if (isset($headers['content-length'])) {
            $this->readExactly($stream, (int) $headers['content-length']);

            return [$status, $reusable];
        }

        // No Content-Length and not chunked: the body can't be delimited safely,
        // so the connection must not be reused.
        return [$status, false];
    }

    private function parseStatus(string $statusLine): int
    {
        $parts = explode(' ', $statusLine, 3);
        if (count($parts) < 2 || ! ctype_digit($parts[1])) {
            throw new RuntimeException("Malformed analytics status line: {$statusLine}");
        }

        return (int) $parts[1];
    }

    /**
     * @param  resource  $stream
     */
    private function drainChunked($stream): void
    {
        while (true) {
            $sizeLine = $this->readLine($stream);
            if ($sizeLine === false) {
                throw new RuntimeException('Unexpected EOF in chunked analytics response');
            }
            $sizeHex = trim(explode(';', $sizeLine, 2)[0]);
            $size = $sizeHex === '' ? 0 : (int) hexdec($sizeHex);
            if ($size === 0) {
                // Consume trailing headers up to the final blank line.
                while (($line = $this->readLine($stream)) !== false && $line !== '') {
                    // discard trailers
                }

                return;
            }
            $this->readExactly($stream, $size);
            $this->readLine($stream); // CRLF after the chunk
        }
    }

    /**
     * @param  resource  $stream
     */
    private function readExactly($stream, int $length): void
    {
        $remaining = $length;
        while ($remaining > 0) {
            $chunk = @fread($stream, $remaining);
            if ($chunk === false || $chunk === '') {
                throw new RuntimeException('Unexpected EOF reading analytics response body');
            }
            $remaining -= strlen($chunk);
        }
    }

    /**
     * @param  resource  $stream
     * @return string|false the line without its trailing CRLF, or false on EOF
     */
    private function readLine($stream): string|false
    {
        $line = @fgets($stream);
        if ($line === false) {
            return false;
        }

        return rtrim($line, "\r\n");
    }

    private function closeStream(): void
    {
        if ($this->stream !== null) {
            @fclose($this->stream);
            $this->stream = null;
        }
    }
}
