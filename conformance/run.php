<?php

/**
 * Conformance runner for the PHP SDK.
 *
 * Reads one scenario JSON on stdin, drives the REAL SDK entrypoint for the
 * scenario's surface, and prints the normalized decision as JSON on stdout.
 * Mirrors connect-sdk-typescript/conformance/run.ts and
 * connect-sdk-python/conformance/run.py. All entrypoints are synchronous.
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Supertab\Connect\Bot\DefaultBotDetector;
use Supertab\Connect\Customer\ContentMatcher;
use Supertab\Connect\Customer\LicenseXmlParser;
use Supertab\Connect\Enum\EnforcementMode;
use Supertab\Connect\Http\HttpClient;
use Supertab\Connect\Http\RequestContext;
use Supertab\Connect\Jwks\JwksProvider;
use Supertab\Connect\License\LicenseTokenVerifier;
use Supertab\Connect\Result\BlockResult;
use Supertab\Connect\Result\RespondResult;
use Supertab\Connect\SupertabConnect;

const MOCK_ORIGIN = 'http://localhost:9999';

/** Extract the four normalized RSL header fields from a (canonical-cased) header map. */
function rsl_headers(array $headers): array
{
    $lower = [];
    foreach ($headers as $k => $v) {
        $lower[strtolower((string) $k)] = $v;
    }
    $wa = null;
    if (isset($lower['www-authenticate']) && preg_match('/error="([^"]+)"/', $lower['www-authenticate'], $m)) {
        $wa = $m[1];
    }
    $link = null;
    if (isset($lower['link']) && preg_match('/<([^>]+)>/', $lower['link'], $m)) {
        $link = $m[1];
    }
    return [
        'www_authenticate_error' => $wa,
        'link_license_url' => $link,
        'x_rsl_status' => $lower['x-rsl-status'] ?? null,
        'x_rsl_reason' => $lower['x-rsl-reason'] ?? null,
    ];
}

function surface_verify(array $in): array
{
    $jwks = new JwksProvider(MOCK_ORIGIN, new HttpClient(), false);
    $verifier = new LicenseTokenVerifier($jwks, MOCK_ORIGIN, false);
    $res = $verifier->verify($in['token'] ?? '', $in['resource_url']);
    if ($res->valid) {
        return ['valid' => true, 'reason' => null];
    }
    return ['valid' => false, 'reason' => $res->reason?->value];
}

function surface_enforce(array $in): array
{
    SupertabConnect::resetInstance();
    SupertabConnect::setBaseUrl(MOCK_ORIGIN);
    $connect = new SupertabConnect(
        apiKey: 'test',
        enforcement: EnforcementMode::from(strtolower((string) $in['enforcement'])),
        botDetector: empty($in['use_default_bot_detector']) ? null : new DefaultBotDetector(),
    );

    $req = $in['request'];
    $headers = [];
    foreach (($req['headers'] ?? []) as $k => $v) {
        $headers[strtolower((string) $k)] = $v;
    }
    $ctx = new RequestContext(
        url: $req['url'],
        authorizationHeader: $headers['authorization'] ?? null,
        userAgent: $headers['user-agent'] ?? null,
        accept: $headers['accept'] ?? null,
        acceptLanguage: $headers['accept-language'] ?? null,
        secChUa: $headers['sec-ch-ua'] ?? null,
        headers: $headers,
    );

    $res = $connect->handleRequest($ctx);
    $status = ($res instanceof BlockResult || $res instanceof RespondResult) ? $res->status : null;
    return [
        'action' => $res->action->value,
        'status' => $status,
        'headers' => rsl_headers($res->headers),
    ];
}

function surface_customer_match(array $in): array
{
    $blocks = LicenseXmlParser::parseContentElements($in['license_xml'], false);
    $best = ContentMatcher::findBestMatch($blocks, $in['resource_url'], false);
    if ($best === null) {
        return ['matched' => false, 'matched_url_pattern' => null, 'token_server' => null, 'requires_token' => false];
    }
    return [
        'matched' => true,
        'matched_url_pattern' => $best->urlPattern,
        'token_server' => $best->server,
        'requires_token' => true,
    ];
}

function surface_customer_obtain(array $in): array
{
    try {
        $token = SupertabConnect::obtainLicenseToken(
            $in['client_id'],
            $in['client_secret'],
            $in['resource_url'],
        );
    } catch (\Throwable $e) {
        return ['outcome' => 'error'];
    }
    return ['outcome' => $token !== '' ? 'mint' : 'no_token'];
}

$scn = json_decode(file_get_contents('php://stdin'), true);
$surface = $scn['surface'];
$in = $scn['input'];

$out = match ($surface) {
    'verify' => surface_verify($in),
    'enforce' => surface_enforce($in),
    'customer-match' => surface_customer_match($in),
    'customer-obtain' => surface_customer_obtain($in),
    default => null,
};

if ($out === null) {
    fwrite(STDERR, "unhandled surface: {$surface}\n");
    exit(2);
}

echo json_encode($out, JSON_UNESCAPED_SLASHES);
