<?php

declare(strict_types=1);

/**
 * Launcher for the self-contained local demo.
 *
 * Generates an EC key pair, starts the local server, runs the bot demo, then cleans up.
 *
 * Usage: php demo/run-local.php
 *        DEMO_PORT=9090 php demo/run-local.php
 */
$port = getenv('DEMO_PORT') ?: '8080';
$keyFile = __DIR__ . '/.local-key.pem';
$serverProcess = null;

// ── Cleanup handler ──────────────────────────────────────

function cleanup(): void
{
    global $serverProcess, $keyFile;

    if ($serverProcess !== null && is_resource($serverProcess)) {
        proc_terminate($serverProcess);
        proc_close($serverProcess);
    }

    if (file_exists($keyFile)) {
        @unlink($keyFile);
    }
}

register_shutdown_function('cleanup');

// ── Generate EC P-256 key pair ───────────────────────────

$key = openssl_pkey_new([
    'curve_name' => 'prime256v1',
    'private_key_type' => OPENSSL_KEYTYPE_EC,
]);

if ($key === false) {
    fwrite(STDERR, "\033[31m  Error: Failed to generate EC key pair. Is ext-openssl installed?\033[0m\n");
    exit(1);
}

openssl_pkey_export($key, $pem);
file_put_contents($keyFile, $pem);

// ── Start the local server ───────────────────────────────

$cmd = PHP_BINARY . ' -S ' . escapeshellarg("localhost:{$port}") . ' ' . escapeshellarg(__DIR__ . '/server.php');

$serverProcess = proc_open(
    $cmd,
    [
        0 => ['file', '/dev/null', 'r'],
        1 => ['file', '/dev/null', 'w'],
        2 => ['file', '/dev/null', 'w'],
    ],
    $pipes,
    __DIR__ . '/..',
    ['DEMO_PORT' => $port],
);

if (! is_resource($serverProcess)) {
    fwrite(STDERR, "\033[31m  Error: Failed to start local server on port {$port}.\033[0m\n");
    exit(1);
}

// ── Wait for server to be ready ──────────────────────────

$ready = false;
for ($i = 0; $i < 20; $i++) {
    usleep(250_000); // 250ms

    $ch = curl_init("http://localhost:{$port}/.well-known/jwks.json/platform");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 1,
        CURLOPT_CONNECTTIMEOUT => 1,
    ]);
    curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    unset($ch);

    if ($status === 200) {
        $ready = true;
        break;
    }
}

if (! $ready) {
    fwrite(STDERR, "\033[31m  Error: Local server failed to start on port {$port} within 5 seconds.\033[0m\n");
    exit(1);
}

// ── Run the bot demo ─────────────────────────────────────

putenv("RESOURCE_URL=http://localhost:{$port}/article");
putenv('SUPERTAB_CLIENT_ID=demo-bot');
putenv('SUPERTAB_CLIENT_SECRET=demo-secret');

require __DIR__ . '/bot.php';
