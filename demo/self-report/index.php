<?php

declare(strict_types=1);

/**
 * Supertab Connect PHP SDK — self-report demo site.
 *
 * Vanilla-PHP publisher: every request flows through
 * SupertabConnect::handleRequest(), so the /.well-known/supertab/status
 * self-report endpoint is served by the SDK with zero endpoint-specific
 * code in this file. See README.md for deploy/register/probe instructions.
 */

require __DIR__ . '/vendor/autoload.php';

use Supertab\Connect\Enum\EnforcementMode;
use Supertab\Connect\Http\HttpClient;
use Supertab\Connect\Http\RequestContext;
use Supertab\Connect\Result\AllowResult;
use Supertab\Connect\SupertabConnect;

// ── Proxy scheme fix-up ──────────────────────────────────────────────
// App Runner terminates TLS and forwards plain HTTP with
// X-Forwarded-Proto: https. Fix $_SERVER before anything derives the
// request origin — otherwise the SDK sees http://…, the backend-minted
// challenge audience (https://…) never matches, and every status probe
// gets the 404 decoy.
if (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') {
    $_SERVER['HTTPS'] = 'on';
}

// ── Health check ─────────────────────────────────────────────────────
// Served before the SDK so App Runner's checks never run bot detection,
// never emit analytics, and never look like traffic.
if (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) === '/healthz') {
    header('Content-Type: text/plain');
    echo "ok\n";
    exit;
}

// ── Configuration (env) ──────────────────────────────────────────────
$apiKey = getenv('SUPERTAB_MERCHANT_API_KEY') ?: '';
if ($apiKey === '') {
    http_response_code(500);
    header('Content-Type: text/plain');
    echo "SUPERTAB_MERCHANT_API_KEY is not set\n";
    exit;
}

$baseUrl = getenv('SUPERTAB_BASE_URL') ?: 'https://api-connect.sbx.supertab.co';
$enforcement = EnforcementMode::tryFrom(getenv('SUPERTAB_ENFORCEMENT') ?: '') ?? EnforcementMode::OBSERVE;
$analytics = ! in_array(getenv('SUPERTAB_ANALYTICS'), ['0', 'false', 'off'], true);

$connect = new SupertabConnect(
    apiKey: $apiKey,
    enforcement: $enforcement,
    baseUrl: $baseUrl,
    analyticsEnabled: $analytics,
);

// ── Every request through the SDK ────────────────────────────────────
$result = $connect->handleRequest(RequestContext::fromGlobals());

foreach ($result->headers as $name => $value) {
    header("{$name}: {$value}");
}

if (! $result instanceof AllowResult) {
    // BLOCK and RESPOND both carry a complete response to emit — the
    // RESPOND branch is what serves the self-report status endpoint.
    http_response_code($result->status);
    echo $result->body;
    exit;
}

// ── Allowed: minimal demo page ───────────────────────────────────────
$sdkVersion = htmlspecialchars(HttpClient::resolveVersion(), ENT_QUOTES, 'UTF-8');
$mode = htmlspecialchars($enforcement->value, ENT_QUOTES, 'UTF-8');

header('Content-Type: text/html; charset=UTF-8');
echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Supertab Connect PHP SDK — self-report demo</title>
</head>
<body>
<h1>Supertab Connect PHP SDK — self-report demo</h1>
<p>This site routes every request through <code>SupertabConnect::handleRequest()</code>.
The backend's status probe is answered at <code>/.well-known/supertab/status</code>
by the SDK itself — there is no endpoint-specific code here.</p>
<ul>
<li>SDK version: <code>{$sdkVersion}</code></li>
<li>Enforcement mode: <code>{$mode}</code></li>
</ul>
</body>
</html>
HTML;
