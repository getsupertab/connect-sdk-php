<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Supertab\Connect\SupertabConnect;

// ── ANSI Colors ────────────────────────────────────────────

function bold(string $s): string
{
    return "\033[1m{$s}\033[0m";
}

function dim(string $s): string
{
    return "\033[2m{$s}\033[0m";
}

function red(string $s): string
{
    return "\033[31m{$s}\033[0m";
}

function green(string $s): string
{
    return "\033[32m{$s}\033[0m";
}

function yellow(string $s): string
{
    return "\033[33m{$s}\033[0m";
}

function cyan(string $s): string
{
    return "\033[36m{$s}\033[0m";
}

function magenta(string $s): string
{
    return "\033[35m{$s}\033[0m";
}

function blue(string $s): string
{
    return "\033[34m{$s}\033[0m";
}

function white(string $s): string
{
    return "\033[97m{$s}\033[0m";
}

function italic(string $s): string
{
    return "\033[3m{$s}\033[0m";
}

// ── Helpers ────────────────────────────────────────────────

function loadEnv(string $path): array
{
    if (! file_exists($path)) {
        return [];
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $env = [];

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        $pos = strpos($line, '=');
        if ($pos === false) {
            continue;
        }
        $key = trim(substr($line, 0, $pos));
        $value = trim(substr($line, $pos + 1));
        if (strlen($value) >= 2 && $value[0] === '"' && $value[-1] === '"') {
            $value = substr($value, 1, -1);
        }
        $env[$key] = $value;
    }

    return $env;
}

/**
 * @param  array<string>  $headers  cURL-style headers (e.g. ["User-Agent: Bot/1.0"])
 * @return array{statusCode: int, body: string, headers: array<string, string>}
 */
function httpGet(string $url, array $headers = []): array
{
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_USERAGENT => 'SupertabConnect-PHP/1.0 DemoBot',
        CURLOPT_FOLLOWLOCATION => false,
    ]);

    $responseHeaders = [];
    curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($ch, $header) use (&$responseHeaders) {
        $len = strlen($header);
        $parts = explode(':', $header, 2);
        if (count($parts) === 2) {
            $name = strtolower(trim($parts[0]));
            $value = trim($parts[1]);
            if (isset($responseHeaders[$name])) {
                $responseHeaders[$name] .= ', ' . $value;
            } else {
                $responseHeaders[$name] = $value;
            }
        }

        return $len;
    });

    $body = (string) curl_exec($ch);
    $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if (curl_errno($ch)) {
        $error = curl_error($ch);
        fwrite(STDERR, red("\n  Connection failed: {$error}\n"));
        fwrite(STDERR, dim("  Check that RESOURCE_URL in demo/.env is reachable.\n\n"));
        exit(1);
    }

    return [
        'statusCode' => $statusCode,
        'body' => $body,
        'headers' => $responseHeaders,
    ];
}

function parseLicenseLink(string $header): ?string
{
    if (preg_match_all('/<([^>]+)>\s*([^,]*)/', $header, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $params = $match[2] ?? '';
            if (preg_match('/rel\s*=\s*"license"/', $params)) {
                return $match[1];
            }
        }

        return $matches[0][1] ?? null;
    }

    return null;
}

function originFromUrl(string $url): string
{
    $parsed = parse_url($url);
    $scheme = $parsed['scheme'] ?? 'https';
    $host = $parsed['host'] ?? 'localhost';
    $port = isset($parsed['port']) ? ":{$parsed['port']}" : '';

    return "{$scheme}://{$host}{$port}";
}

function decodeJwtPayload(string $token): ?array
{
    $segments = explode('.', $token);
    if (count($segments) !== 3) {
        return null;
    }

    $payload = $segments[1];
    $remainder = strlen($payload) % 4;
    if ($remainder !== 0) {
        $payload .= str_repeat('=', 4 - $remainder);
    }

    $json = base64_decode(strtr($payload, '-_', '+/'), true);
    if ($json === false) {
        return null;
    }

    try {
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return is_array($data) ? $data : null;
    } catch (\JsonException) {
        return null;
    }
}

function banner(int $step, int $total, string $title, string $emoji): void
{
    echo "\033[2J\033[H";

    $dots = '';
    for ($i = 1; $i <= $total; $i++) {
        if ($i < $step) {
            $dots .= green('●') . ' ';
        } elseif ($i === $step) {
            $dots .= yellow('●') . ' ';
        } else {
            $dots .= dim('○') . ' ';
        }
    }

    echo "\n";
    echo '  ' . yellow(bold('Supertab Connect — Demo')) . "\n";
    echo '  ' . dim(str_repeat('─', 60)) . "\n";
    echo "  {$dots} " . dim("Step {$step}/{$total}") . "\n";
    echo "\n";
    echo "  {$emoji}  " . bold(white($title)) . "\n";
    echo '  ' . dim(str_repeat('─', 60)) . "\n";
}

function botSays(string $text): void
{
    echo "\n  " . blue('🤖 Bot:') . ' ' . italic("\"{$text}\"") . "\n";
}

function showRequest(string $method, string $url, array $displayHeaders = []): void
{
    echo "\n  " . cyan("→ {$method} {$url}") . "\n";
    foreach ($displayHeaders as $name => $value) {
        echo '    ' . dim("{$name}: {$value}") . "\n";
    }
}

function showStatus(int $status, string $statusText = ''): void
{
    $color = $status >= 200 && $status < 300 ? 'green' : 'red';
    $icon = $status >= 200 && $status < 300 ? '✅' : '❌';
    if ($statusText === '') {
        $statusText = match ($status) {
            200 => 'OK',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not Found',
            503 => 'Service Unavailable',
            default => '',
        };
    }
    echo "\n  {$icon} " . $color(bold((string) $status)) . ' ' . $color($statusText) . "\n";
}

function showHeader(string $name, ?string $value): void
{
    if ($value === null) {
        return;
    }
    echo '    ' . yellow($name) . dim(': ') . $value . "\n";
}

function showLinkHeaders(?string $combinedLink): void
{
    if ($combinedLink === null) {
        return;
    }
    if (preg_match_all('/<([^>]+)>\s*[^,]*/', $combinedLink, $matches)) {
        foreach ($matches[0] as $linkValue) {
            echo '    ' . yellow('Link') . dim(': ') . trim($linkValue) . "\n";
        }
    } else {
        echo '    ' . yellow('Link') . dim(': ') . $combinedLink . "\n";
    }
}

function showRslHeaders(array $headers): void
{
    showHeader('WWW-Authenticate', $headers['www-authenticate'] ?? null);
    showHeader('X-RSL-Status', $headers['x-rsl-status'] ?? null);
    showHeader('X-RSL-Reason', $headers['x-rsl-reason'] ?? null);
    showLinkHeaders($headers['link'] ?? null);
}

function showJwtClaims(string $token): void
{
    $claims = decodeJwtPayload($token);
    if ($claims === null) {
        return;
    }

    echo "\n  " . magenta(bold('Token claims:')) . "\n";

    $display = [
        'sub' => $claims['sub'] ?? null,
        'aud' => is_array($claims['aud'] ?? null)
            ? implode(', ', $claims['aud'])
            : ($claims['aud'] ?? null),
        'scope' => $claims['scope'] ?? null,
        'iss' => $claims['iss'] ?? null,
        'iat' => isset($claims['iat'])
            ? date('Y-m-d\TH:i:sP', (int) $claims['iat'])
            : null,
        'exp' => isset($claims['exp'])
            ? date('Y-m-d\TH:i:sP', (int) $claims['exp'])
            : null,
    ];

    foreach ($display as $key => $value) {
        if ($value !== null) {
            echo '    ' . magenta("{$key}: ") . white($value) . "\n";
        }
    }
}

function waitForEnter(string $prompt = 'Press ENTER to continue...'): void
{
    echo "\n  " . dim($prompt);
    fgets(STDIN);
}

// ── Configuration ──────────────────────────────────────────

$env = loadEnv(__DIR__ . '/.env');

$resourceUrl = getenv('RESOURCE_URL') ?: ($env['RESOURCE_URL'] ?? '');
$clientId = getenv('SUPERTAB_CLIENT_ID') ?: ($env['SUPERTAB_CLIENT_ID'] ?? '');
$clientSecret = getenv('SUPERTAB_CLIENT_SECRET') ?: ($env['SUPERTAB_CLIENT_SECRET'] ?? '');

if ($resourceUrl === '' || $clientId === '' || $clientSecret === '') {
    fwrite(STDERR, red("\n  Error: RESOURCE_URL, SUPERTAB_CLIENT_ID, and SUPERTAB_CLIENT_SECRET are required.\n"));
    fwrite(STDERR, dim("  Copy demo/.env.example to demo/.env and fill in your credentials.\n\n"));
    exit(1);
}

// ── Title Screen ───────────────────────────────────────────

$totalSteps = 6;

echo "\033[2J\033[H";
echo "\n";
echo '  ' . yellow(bold('╔══════════════════════════════════════════════════╗')) . "\n";
echo '  ' . yellow(bold('║')) . '        ' . bold('Supertab Connect — Demo') . '                   ' . yellow(bold('║')) . "\n";
echo '  ' . yellow(bold('╚══════════════════════════════════════════════════╝')) . "\n";
echo "\n";
echo '  ' . dim('Resource:') . "     {$resourceUrl}\n";
echo '  ' . dim('Client:') . "       {$clientId}\n";

waitForEnter('Press ENTER to start the demo...');

// ── Step 2: Bot requests content — blocked! ─────────────────

banner(2, $totalSteps, 'Bot requests content — blocked!', '🚪');
botSays('Let me grab that article again like I always do...');
showRequest('GET', $resourceUrl, ['User-Agent' => 'DemoBot/1.0']);

$response = httpGet($resourceUrl, ['User-Agent: DemoBot/1.0']);

$isBlocked = $response['statusCode'] === 401;

showStatus($response['statusCode'], $isBlocked ? 'Unauthorized' : 'OK');
echo "\n  " . dim('Response headers:') . "\n";
showRslHeaders($response['headers']);

botSays("Wait — what happened?! I was just here! ...what's this Link header?");
waitForEnter();

// ── Step 3: Bot discovers license.xml ──────────────────────

banner(3, $totalSteps, 'Bot discovers license.xml', '📄');

$linkHeader = $response['headers']['link'] ?? '';
$licenseUrl = parseLicenseLink($linkHeader) ?? originFromUrl($resourceUrl) . '/license.xml';

botSays('Let me follow that Link header...');
showRequest('GET', $licenseUrl);

$response2 = httpGet($licenseUrl);
showStatus($response2['statusCode'], 'OK');

echo "\n  " . dim('license.xml content:') . "\n";
$lines = explode("\n", $response2['body']);
$displayLines = array_values(array_filter(
    array_map('rtrim', $lines),
    fn (string $l) => trim($l) !== ''
));
$displayCount = min(count($displayLines), 20);
for ($i = 0; $i < $displayCount; $i++) {
    $line = $displayLines[$i];
    $trimmed = trim($line);
    if (str_contains($trimmed, '<permits')) {
        echo '  ' . cyan($line) . "\n";
    } elseif (str_contains($trimmed, '<payment')) {
        echo '  ' . yellow($line) . "\n";
    } elseif (str_contains($trimmed, '<amount')) {
        echo '  ' . green($line) . "\n";
    } else {
        echo '  ' . dim($line) . "\n";
    }
}
$remaining = count($displayLines) - 20;
if ($remaining > 0) {
    echo '  ' . dim("... ({$remaining} more lines)") . "\n";
}

botSays('OK, I know the rules now. Let me get a license token.');
waitForEnter();

// ── Step 4: Bot gets a license token ────────────────────────

banner(4, $totalSteps, 'Bot gets a license token', '🔑');
botSays('Let me authenticate and request a license...');

echo "\n  " . dim('Requesting token via SDK:') . "\n";
echo '    ' . dim('clientId:') . "     {$clientId}\n";
echo '    ' . dim('resourceUrl:') . "  {$resourceUrl}\n";

try {
    $token = SupertabConnect::obtainLicenseToken(
        clientId: $clientId,
        clientSecret: $clientSecret,
        resourceUrl: $resourceUrl,
    );
} catch (\Throwable $e) {
    fwrite(STDERR, red("\n  Failed to obtain token: " . $e->getMessage()) . "\n");
    exit(1);
}

echo "\n  " . green(bold('✅ Token received!')) . "\n";
echo "\n  " . dim('JWT:') . ' ' . substr($token, 0, 40) . dim('...') . "\n";
showJwtClaims($token);

botSays('Got my token! Now let me try that article again...');
waitForEnter();

// ── Step 5: Bot retries with token — access granted! ────────

banner(5, $totalSteps, 'Bot retries with token — access granted!', '🎉');
botSays('Same article, but this time I have my license...');

$truncatedAuth = 'License ' . substr($token, 0, 30) . '...';
showRequest('GET', $resourceUrl, [
    'User-Agent' => 'DemoBot/1.0',
    'Authorization' => $truncatedAuth,
]);

$response4 = httpGet($resourceUrl, [
    'User-Agent: DemoBot/1.0',
    'Authorization: License ' . $token,
]);

showStatus($response4['statusCode'], 'OK');

$contentType = $response4['headers']['content-type'] ?? '';
$preview = substr($response4['body'], 0, 200);
$preview = str_replace("\n", ' ', $preview);
$preview = trim($preview);

echo "\n  " . dim("Content-Type: {$contentType}") . "\n";
echo '  ' . dim('Body preview:') . "\n";
echo '  ' . white($preview) . dim('...') . "\n";

botSays('Content served! And this time the publisher gets paid.');
waitForEnter();

// ── Step 6: Fake token gets rejected ───────────────────────

banner(6, $totalSteps, 'Bonus: what about a fake token?', '🛡️');
botSays('What if someone tries to skip the license?');

showRequest('GET', $resourceUrl, [
    'User-Agent' => 'DemoBot/1.0',
    'Authorization' => 'License not.a.real.token',
]);

$response5 = httpGet($resourceUrl, [
    'User-Agent: DemoBot/1.0',
    'Authorization: License not.a.real.token',
]);

showStatus($response5['statusCode'], 'Unauthorized');
echo "\n  " . dim('Response headers:') . "\n";
showHeader('WWW-Authenticate', $response5['headers']['www-authenticate'] ?? null);

botSays('Nope. Token verification will fail.');
waitForEnter();

// ── Done ──────────────────────────────────────────────────

echo "\033[2J\033[H";
$allDone = implode(' ', array_fill(0, $totalSteps, green('●')));
echo "\n";
echo '  ' . dim(str_repeat('─', 60)) . "\n";
echo "  {$allDone}  " . green(bold('All steps complete')) . "\n";
echo '  ' . dim(str_repeat('─', 60)) . "\n";
echo "\n";
echo '  ' . yellow(bold('╔══════════════════════════════════════════════════╗')) . "\n";
echo '  ' . yellow(bold('║')) . '        ' . bold('Demo Complete') . '                             ' . yellow(bold('║')) . "\n";
echo '  ' . yellow(bold('║')) . '                                                  ' . yellow(bold('║')) . "\n";
echo '  ' . yellow(bold('║')) . '  ' . dim('The full RSL lifecycle:') . '                         ' . yellow(bold('║')) . "\n";
echo '  ' . yellow(bold('║')) . '  ' . red('✗') . ' Before: bots access content for free          ' . yellow(bold('║')) . "\n";
echo '  ' . yellow(bold('║')) . '  ' . green('✓') . ' Publisher deploys license + verification      ' . yellow(bold('║')) . "\n";
echo '  ' . yellow(bold('║')) . '  ' . green('✓') . ' Agreement between bot operator & publisher    ' . yellow(bold('║')) . "\n";
echo '  ' . yellow(bold('║')) . '  ' . green('✓') . ' Bot discovers terms, gets licensed            ' . yellow(bold('║')) . "\n";
echo '  ' . yellow(bold('║')) . '  ' . green('✓') . ' Token verification                            ' . yellow(bold('║')) . "\n";
echo '  ' . yellow(bold('║')) . '  ' . green('✓') . ' Content served, publisher gets paid           ' . yellow(bold('║')) . "\n";
echo '  ' . yellow(bold('║')) . '                                                  ' . yellow(bold('║')) . "\n";
echo '  ' . yellow(bold('╚══════════════════════════════════════════════════╝')) . "\n";
echo "\n";
