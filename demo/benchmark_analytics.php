<?php

declare(strict_types=1);

/**
 * Benchmark: fresh-connection-per-emit vs. keep-alive analytics transport.
 *
 * Forks a tiny loopback HTTP/1.1 server that simulates a per-connection
 * handshake cost (a fixed sleep paid once per NEW connection — standing in for
 * the TCP+TLS handshake you'd pay against the real relay). It then fires N
 * analytics events through each transport and reports the wall-clock time.
 *
 *   - HttpAnalyticsTransport       opens a fresh connection per emit  → pays the
 *                                  handshake N times.
 *   - KeepAliveHttpAnalyticsTransport reuses one connection           → pays it once.
 *
 * Run: php demo/benchmark_analytics.php
 *
 * Note: on loopback there is no real TLS handshake, so the SIMULATED delay below
 * stands in for it. Against a real HTTPS relay the per-connection saving is
 * typically larger (a TLS handshake is 1–2 extra round trips).
 */

require __DIR__ . '/../vendor/autoload.php';

use Supertab\Connect\Analytics\AnalyticsEventFactory;
use Supertab\Connect\Analytics\Decision;
use Supertab\Connect\Analytics\Enum\FinalAction;
use Supertab\Connect\Analytics\Enum\TokenOutcome;
use Supertab\Connect\Analytics\HttpAnalyticsTransport;
use Supertab\Connect\Analytics\KeepAliveHttpAnalyticsTransport;
use Supertab\Connect\Enum\EnforcementMode;
use Supertab\Connect\Http\HttpClient;
use Supertab\Connect\Http\RequestContext;

const EMITS = 25;
const SETUP_DELAY_MS = 30;

if (! function_exists('pcntl_fork') || ! function_exists('stream_socket_server')) {
    fwrite(STDERR, "This benchmark needs the pcntl extension and stream sockets (CLI PHP).\n");
    exit(0);
}

$server = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if ($server === false) {
    fwrite(STDERR, "Could not open loopback server: {$errstr} ({$errno})\n");
    exit(1);
}
$address = stream_socket_get_name($server, false);
$port = (int) substr($address, (int) strrpos($address, ':') + 1);

$pid = pcntl_fork();
if ($pid === -1) {
    fwrite(STDERR, "Could not fork.\n");
    exit(1);
}

if ($pid === 0) {
    // ---- Child: the loopback server ----
    bench_server($server, SETUP_DELAY_MS * 1000);
    exit(0);
}

// ---- Parent: the benchmark ----
fclose($server); // only the child accepts

$event = (new AnalyticsEventFactory)->build(
    new RequestContext(
        url: 'https://example.com/premium/article',
        userAgent: 'BenchBot/1.0',
        method: 'GET',
        clientIp: '203.0.113.1',
    ),
    new Decision(
        hasToken: false,
        tokenOutcome: TokenOutcome::ABSENT,
        finalAction: FinalAction::ALLOW,
        enforcementMode: EnforcementMode::SOFT,
    ),
);

$baseUrl = "http://127.0.0.1:{$port}";

// Phase 1 — current default: a fresh connection per emit.
$fresh = new HttpAnalyticsTransport(
    apiKey: 'bench-key',
    baseUrl: $baseUrl,
    httpClient: new HttpClient(timeout: 2),
);
$start = microtime(true);
for ($i = 0; $i < EMITS; $i++) {
    $fresh->emit($event);
}
$freshMs = (microtime(true) - $start) * 1000;

// Phase 2 — adaptive: persistent socket reused across emits (cURL fallback).
$adaptive = KeepAliveHttpAnalyticsTransport::adaptive('bench-key', $baseUrl, 2);
$start = microtime(true);
for ($i = 0; $i < EMITS; $i++) {
    $adaptive->emit($event);
}
$adaptiveMs = (microtime(true) - $start) * 1000;

// Release the reused socket so the child can go idle and exit, then reap it.
unset($fresh, $adaptive);
pcntl_waitpid($pid, $status);

printf("\nAnalytics transport benchmark\n");
printf("  emits per phase ............ %d\n", EMITS);
printf("  simulated handshake cost ... %d ms / new connection\n\n", SETUP_DELAY_MS);
printf("  fresh connection per emit .. %7.1f ms total  (%5.1f ms/emit)\n", $freshMs, $freshMs / EMITS);
printf("  adaptive (reused socket) ... %7.1f ms total  (%5.1f ms/emit)\n", $adaptiveMs, $adaptiveMs / EMITS);
printf("\n  speedup .................... %.1fx   (saved %.0f ms)\n\n", $freshMs / max($adaptiveMs, 0.001), $freshMs - $adaptiveMs);

/**
 * Minimal HTTP/1.1 keep-alive server. Sleeps once per accepted connection to
 * simulate handshake cost, then serves any number of requests on that
 * connection. Exits once it has served work and the clients have gone idle.
 *
 * @param  resource  $server
 */
function bench_server($server, int $setupDelayUs): void
{
    $servedAny = false;
    $deadline = microtime(true) + 15.0; // hard safety stop

    while (microtime(true) < $deadline) {
        $conn = @stream_socket_accept($server, 1.0);
        if ($conn === false) {
            if ($servedAny) {
                return; // idle after serving → the benchmark is done
            }
            continue;
        }

        usleep($setupDelayUs); // simulated per-connection handshake
        stream_set_timeout($conn, 1);

        while (true) {
            if (bench_read_request($conn) === null) {
                break; // client closed the connection
            }
            $servedAny = true;
            fwrite($conn, "HTTP/1.1 202 Accepted\r\nContent-Length: 0\r\nConnection: keep-alive\r\n\r\n");
        }
        fclose($conn);
    }
}

/**
 * Read one HTTP request (headers + body per Content-Length) so the connection
 * is positioned for the next keep-alive request. Returns null on close/timeout.
 *
 * @param  resource  $conn
 */
function bench_read_request($conn): ?string
{
    $buffer = '';
    while (! str_contains($buffer, "\r\n\r\n")) {
        $chunk = fread($conn, 4096);
        if ($chunk === '' || $chunk === false) {
            return null;
        }
        $buffer .= $chunk;
    }

    $contentLength = 0;
    if (preg_match('/content-length:\s*(\d+)/i', $buffer, $matches)) {
        $contentLength = (int) $matches[1];
    }

    $bodyRead = strlen(substr($buffer, (int) strpos($buffer, "\r\n\r\n") + 4));
    $remaining = $contentLength - $bodyRead;
    while ($remaining > 0) {
        $chunk = fread($conn, $remaining);
        if ($chunk === '' || $chunk === false) {
            break;
        }
        $remaining -= strlen($chunk);
    }

    return $buffer;
}
