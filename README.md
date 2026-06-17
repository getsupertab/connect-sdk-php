# Supertab Connect PHP SDK

Check our [documentation](https://connect-docs.supertab.co/introduction/overview) for more information on Supertab Connect.

[![Packagist Version](https://img.shields.io/packagist/v/getsupertab/connect-sdk-php.svg)](https://packagist.org/packages/getsupertab/connect-sdk-php)
[![License](https://img.shields.io/packagist/l/getsupertab/connect-sdk-php.svg)](https://github.com/getsupertab/connect-sdk-php/blob/main/LICENSE)
[![CI](https://img.shields.io/github/actions/workflow/status/getsupertab/connect-sdk-php/ci.yml?branch=main)](https://github.com/getsupertab/connect-sdk-php/actions/workflows/ci.yml)
[![PHP Version](https://img.shields.io/packagist/php-v/getsupertab/connect-sdk-php.svg)](https://packagist.org/packages/getsupertab/connect-sdk-php)
[![Code Style](https://img.shields.io/badge/code%20style-PHPCS-4F5B93.svg)](https://github.com/PHPCSStandards/PHP_CodeSniffer)

## Installation

```bash
composer require getsupertab/connect-sdk-php
```

**Requirements:** PHP 8.1+, extensions: `ext-curl`, `ext-json`, `ext-openssl`, `ext-simplexml`

## Quick Start

**Publisher — verify incoming requests:**

```php
use Supertab\Connect\SupertabConnect;
use Supertab\Connect\Enum\EnforcementMode;
use Supertab\Connect\Result\AllowResult;
use Supertab\Connect\Result\BlockResult;

$connect = new SupertabConnect(
    apiKey: 'stc_live_your_api_key',
    enforcement: EnforcementMode::STRICT,
);

$result = $connect->handleRequest();

// Send returned RSL headers (Link, WWW-Authenticate, X-RSL-Status, etc.)
foreach ($result->headers as $name => $value) {
    header("{$name}: {$value}");
}

if ($result instanceof BlockResult) {
    http_response_code($result->status);
    echo $result->body;
    exit;
}

// Token is valid — serve content
```

**Bot — obtain a license token (client credentials):**

```php
use Supertab\Connect\SupertabConnect;

$token = SupertabConnect::obtainLicenseToken(
    clientId: 'your_client_id',
    clientSecret: 'your_client_secret',
    resourceUrl: 'https://example.com/article/my-slug',
);

$ch = curl_init('https://example.com/article/my-slug');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ["Authorization: License {$token}"],
]);
$response = curl_exec($ch);
```

**Bot — generate a license token (private key JWT assertion):**

```php
use Supertab\Connect\SupertabConnect;

// 1. Fetch the publisher's license.xml
$licenseXml = file_get_contents('https://example.com/license.xml');

// 2. Generate a token using your private key
$token = SupertabConnect::generateLicenseToken(
    clientId: 'urn:stc:customer:system:your-system-id',
    kid: 'your-key-id',
    privateKeyPem: file_get_contents('/path/to/private-key.pem'),
    resourceUrl: 'https://example.com/article/my-slug',
    licenseXml: $licenseXml,
);

// 3. Access content with the token
$ch = curl_init('https://example.com/article/my-slug');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ["Authorization: License {$token}"],
]);
$response = curl_exec($ch);
```

## Enforcement Modes

The `EnforcementMode` enum controls how `handleRequest()` responds to detected bots when a token is absent or invalid. Non-bot requests without a token are always allowed regardless of mode. Requests with an invalid token are always blocked (except in DISABLED mode).

| Mode | Behavior |
|------|----------|
| `STRICT` | Bots without a valid token are blocked (401/403 with `WWW-Authenticate` header). Invalid tokens from any source are rejected. |
| `SOFT` | All requests allowed. Bots without a token receive `X-RSL-Status: token_required` and `Link` headers to signal that licensing is available. Invalid tokens are still rejected. |
| `DISABLED` | All requests allowed unconditionally — no bot detection, no token verification, even if a token is present. |

Default is `SOFT`.

---

## Analytics

When enabled, the SDK emits **one analytics event per request** to the Supertab Connect relay at `{baseUrl}/ingest/events`, carrying bot-classification signals (user agent, client IP, request metadata, and the verification/enforcement decision). It is **off by default** — enable it with `analyticsEnabled: true`:

```php
$connect = new SupertabConnect(
    apiKey: 'stc_live_your_api_key',
    analyticsEnabled: true,
);
```

- **Fail-open.** Emission never throws or alters request handling; errors are swallowed and the relay POST uses a short timeout.
- **Isolated from billing.** Analytics goes only to `/ingest/events`; the billing `/events` path is untouched.

---

## API Reference

### `new SupertabConnect()`

Creates a singleton instance. Returns the existing instance if one already exists with the same `apiKey`. Throws if an instance with a different `apiKey` already exists.

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| `apiKey` | `string` | Yes | — | Your Supertab Connect API key (`stc_live_...` or `stc_sandbox_...`) |
| `enforcement` | `EnforcementMode` | No | `SOFT` | How to handle missing or invalid tokens |
| `debug` | `bool` | No | `false` | Emit debug logs via `error_log()` |
| `baseUrl` | `?string` | No | `null` | Set the global default base URL (same as `setBaseUrl()`) |
| `httpClient` | `?HttpClientInterface` | No | `null` | Inject a custom HTTP client (defaults to built-in cURL client) |
| `botDetector` | `?BotDetectorInterface` | No | `null` | Inject a custom bot detector (defaults to `DefaultBotDetector`) |
| `analyticsEnabled` | `bool` | No | `false` | Emit one relay analytics event per request to `{baseUrl}/ingest/events` (see [Analytics](#analytics)) |

### `handleRequest(?RequestContext $context): HandlerResult`

Handles an incoming request end-to-end: extracts the license token from the `Authorization` header, verifies it, runs bot detection, records a billing event, emits one relay analytics event (when analytics is enabled), and applies the enforcement mode. When a token is present, it is verified (unless DISABLED mode). When no token is present, bot detection determines whether enforcement kicks in — non-bot requests are always allowed. Returns a result object — the caller is responsible for sending HTTP headers and status codes.

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| `context` | `?RequestContext` | No | `null` | Request info. Defaults to `RequestContext::fromGlobals()` which reads from `$_SERVER`. |

**Returns:** `HandlerResult` — either `AllowResult` (action: ALLOW) or `BlockResult` (action: BLOCK, with `status`, `body`, and `headers`).

When integrating with a framework, pass a `RequestContext` instead of relying on `$_SERVER`:

```php
use Supertab\Connect\Http\RequestContext;

// `headers` must be a flat array<string, string>. Join multi-value headers
// (e.g. `implode(', ', $values)`) before passing them in; non-string values
// are silently dropped.
$headers = [];
foreach ($request->headers->all() as $name => $values) {
    $headers[$name] = is_array($values) ? implode(', ', $values) : (string) $values;
}

$ctx = new RequestContext(
    url: $request->getUri(),
    authorizationHeader: $request->header('Authorization'),
    userAgent: $request->header('User-Agent'),
    accept: $request->header('Accept'),           // used by bot detection
    acceptLanguage: $request->header('Accept-Language'), // used by bot detection
    secChUa: $request->header('Sec-CH-UA'),        // used by bot detection
    headers: $headers,                             // forwarded into event properties (h_* prefix)
    method: $request->method(),                    // analytics
    clientIp: $request->ip(),                      // analytics — your framework's trusted client IP
    // Optionally inject edge signals if your stack provides them (never auto-derived):
    // requestCountry: $request->header('CF-IPCountry'),
    // requestAsn: 13335,
    // tlsFingerprint: $request->header('CF-JA3'),
);

$result = $connect->handleRequest($ctx);
```

All entries in `headers` are forwarded to the analytics event under an `h_<lowercased-name>` key. Credential and PII headers (`authorization`, `cookie`, `set-cookie`, `proxy-authorization`, `x-api-key`, `x-amz-security-token`, `user-agent`, `x-license-auth`, `forwarded`, `x-forwarded-for`, `x-real-ip`, `cf-connecting-ip`, `true-client-ip`) are filtered out. `RequestContext::fromGlobals()` populates `headers` automatically from `$_SERVER`.

### `SupertabConnect::verify()` (static)

Pure token verification without creating an instance. Does not apply enforcement mode or set response headers.

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| `token` | `string` | Yes | — | Raw JWT token (without the `License ` prefix) |
| `resourceUrl` | `string` | Yes | — | The URL being accessed |
| `baseUrl` | `?string` | No | `null` | Per-call override (does not change the global default) |
| `debug` | `bool` | No | `false` | Emit debug logs |
| `httpClient` | `?HttpClientInterface` | No | `null` | Inject a custom HTTP client |

**Returns:** `VerificationResult` with `valid: bool` and `error: ?string`.

```php
$result = SupertabConnect::verify(
    token: $token,
    resourceUrl: 'https://example.com/article/my-slug',
);

if (! $result->valid) {
    http_response_code(401);
    echo $result->error;
    exit;
}
```

### `$connect->verifyAndRecord()`

Verifies a license token and records an analytics event. Requires an instance (uses the instance's `apiKey` for event recording).

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| `token` | `string` | Yes | — | Raw JWT token (without the `License ` prefix) |
| `resourceUrl` | `string` | Yes | — | The URL being accessed |
| `userAgent` | `?string` | No | `null` | User-Agent string for analytics |
| `requestHeaders` | `?array<string, string>` | No | `null` | Incoming request headers to forward into event properties under an `h_` prefix (credential/PII headers filtered out) |

**Returns:** `VerificationResult` with `valid: bool` and `error: ?string`.

```php
$connect = new SupertabConnect(apiKey: 'stc_live_your_api_key');

$result = $connect->verifyAndRecord(
    token: $token,
    resourceUrl: 'https://example.com/article/my-slug',
    userAgent: $_SERVER['HTTP_USER_AGENT'] ?? null,
    requestHeaders: getallheaders() ?: [],
);

if (! $result->valid) {
    http_response_code(401);
    echo $result->error;
    exit;
}
```

### `SupertabConnect::fetchLicenseXml()` (static)

Fetches the RSL license XML for a merchant system from the Supertab Connect API.

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| `merchantSystemUrn` | `string` | Yes | — | Your merchant system URN (`urn:supertab:system:...`) |
| `baseUrl` | `?string` | No | `null` | Per-call override (does not change the global default) |
| `httpClient` | `?HttpClientInterface` | No | `null` | Inject a custom HTTP client |

**Returns:** `string` (the raw XML body). Throws `SupertabConnectException` on failure.

```php
$xml = SupertabConnect::fetchLicenseXml(
    merchantSystemUrn: 'urn:supertab:system:your_system_id',
);

header('Content-Type: application/rsl+xml');
echo $xml;
```

### `SupertabConnect::obtainLicenseToken()` (static)

Obtains a license token for accessing a protected resource using the OAuth2 `client_credentials` flow.

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| `clientId` | `string` | Yes | — | OAuth2 client ID |
| `clientSecret` | `string` | Yes | — | OAuth2 client secret |
| `resourceUrl` | `string` | Yes | — | Full URL of the protected resource |
| `debug` | `bool` | No | `false` | Emit debug logs |
| `httpClient` | `?HttpClientInterface` | No | `null` | Inject a custom HTTP client |

**Returns:** `string` (the access token). Throws `SupertabConnectException` on failure.

The SDK handles the full RSL flow automatically:

1. Fetches `{origin}/license.xml` from the resource URL
2. Parses content blocks and finds the best matching URL pattern (exact > path pattern > wildcard by specificity)
3. POSTs to the token endpoint using OAuth2 `client_credentials`
4. Caches the token in memory (keyed by `clientId:resourceUrl`, reused until 30s before expiry)

### `SupertabConnect::generateLicenseToken()` (static)

Generates a license token using a private key JWT assertion. The caller provides the license XML (typically fetched from the publisher's `license.xml` endpoint), and the SDK parses it, matches the resource URL, and authenticates using a signed JWT client assertion instead of a shared secret.

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| `clientId` | `string` | Yes | — | Your system's client ID (`urn:stc:customer:system:...`) |
| `kid` | `string` | Yes | — | Key ID identifying your registered public key |
| `privateKeyPem` | `string` | Yes | — | Private key in PEM format (RSA or P-256 EC) |
| `resourceUrl` | `string` | Yes | — | Full URL of the protected resource |
| `licenseXml` | `string` | Yes | — | The publisher's license XML content |
| `debug` | `bool` | No | `false` | Emit debug logs |
| `httpClient` | `?HttpClientInterface` | No | `null` | Inject a custom HTTP client |

**Returns:** `string` (the access token). Throws `SupertabConnectException` on failure.

The SDK handles the token exchange automatically:

1. Parses the license XML and finds the best matching content block for the resource URL
2. Creates a short-lived JWT assertion (5 min) signed with your private key
3. POSTs to the token endpoint with `grant_type=rsl` and the JWT assertion
4. Caches the token in memory (keyed by `clientId:resourceUrl`, reused until 30s before expiry)

Supports ES256 (P-256 EC) and RS256 (RSA) private keys. The algorithm is detected automatically from the key.

### `SupertabConnect::setBaseUrl()` (static)

Sets the global default base URL for all API requests. Useful for sandbox/testing environments. This affects all subsequent calls (both instance and static methods).

```php
SupertabConnect::setBaseUrl('https://api-connect.sbx.supertab.co');
```

### `SupertabConnect::getBaseUrl()` (static)

Returns the current global default base URL.

### `SupertabConnect::resetInstance()` (static)

Clears the singleton instance, allowing a new one to be created with different configuration.

---

## Result Types

### `HandlerResult` (returned by `handleRequest()`)

| Property | Type | Description |
|----------|------|-------------|
| `action` | `HandlerAction` | `ALLOW` or `BLOCK` |
| `headers` | `array<string, string>` | RSL response headers |

`BlockResult` adds `status: int` and `body: string`.

```php
foreach ($result->headers as $name => $value) {
    header("{$name}: {$value}");
}

if ($result instanceof BlockResult) {
    http_response_code($result->status);
    echo $result->body;
    exit;
}

// AllowResult — serve your content
```

### `VerificationResult` (returned by `verify()`)

| Property | Type | Description |
|----------|------|-------------|
| `valid` | `bool` | Whether the token is valid |
| `error` | `?string` | Human-readable reason if invalid |

---

## Debug Logging

Pass `debug: true` to the constructor or static methods to log internal steps via `error_log()`:

```
[SupertabConnect] Fetching license.xml from https://example.com/license.xml
[SupertabConnect] Found 2 content block(s)
[SupertabConnect] Best match: https://example.com/* (server: https://api-connect.supertab.co)
[SupertabConnect] Token obtained and cached
```
