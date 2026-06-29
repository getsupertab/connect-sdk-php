<?php

declare(strict_types=1);

namespace Supertab\Connect\Analytics;

use DateTimeImmutable;
use DateTimeZone;
use Supertab\Connect\Enum\EnforcementMode;
use Supertab\Connect\Http\RequestContext;

/**
 * Builds an {@see AnalyticsEvent} from a request context and a {@see Decision}.
 *
 * Port of the TypeScript SDK's buildAnalyticsEvent(). The PHP SDK runs at the
 * origin, so classification signals come from $_SERVER (via
 * {@see RequestContext::fromGlobals()}) or are injected explicitly on the
 * context — never auto-derived from CDN-specific headers.
 */
final class AnalyticsEventFactory
{
    // Defensive cap on client-controlled free-form strings, applied at the edge
    // and mirrored by the relay.
    private const MAX_FIELD_LENGTH = 512;

    // Edge-injected headers are CDN artifacts, not client signals — stripped so
    // header_names reflects only what the client actually sent. Covers all three
    // CDNs (Cloudflare cf-*, Fastly fastly-*, CloudFront cloudfront-*), the
    // shared x-forwarded-* / x-real-ip, and the SDK's own routing header.
    private const EDGE_HEADER_PREFIXES = ['cf-', 'fastly-', 'cloudfront-', 'x-forwarded-'];

    private const EDGE_HEADER_NAMES = ['x-real-ip', 'x-original-request-url'];

    // Mechanical exploit markers for the query-string heuristic, matched case-
    // insensitively against the raw and URL-decoded query. A coarse signal only —
    // real classification stays query-time in the warehouse.
    private const SUSPICIOUS_QUERY_MARKERS = ['../', '..\\', 'union select', '<script', 'onerror=', '/etc/passwd'];

    /**
     * The PHP SDK runs on the origin server, not inside a CDN, so by default it
     * reports no CDN: source_cdn is emitted as null (the relay accepts a null
     * source_cdn for SDK-originated events). A CDN-fronted caller can still pass
     * a provider name.
     */
    public function __construct(
        private readonly ?string $sourceCdn = null,
    ) {}

    public function build(RequestContext $context, Decision $decision, ?DateTimeImmutable $now = null): AnalyticsEvent
    {
        $timestamp = ($now ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('UTC'));

        // RequestContext::$headers may carry original casing when the context is
        // constructed manually (frameworks, getallheaders()); keys are normalized
        // at point of use, mirroring Headers::toEventProperties().
        $headers = array_change_key_case($context->headers);

        // Parse the URL once: null means "not an absolute URL" (no host), which
        // mirrors the edge SDK's `new URL()` throwing — path/host/query then
        // degrade to empty/null rather than guessing.
        $url = $this->safeUrl($context->url);
        $query = $this->querySignals($url);
        $cdn = $context->cdnSignals;

        return new AnalyticsEvent(
            timestamp: $timestamp->format('Y-m-d\TH:i:s.v\Z'),
            requestId: $this->resolveRequestId($context, $headers),
            sourceCdn: $this->sourceCdn,
            userAgent: $context->userAgent ?? '',
            clientIp: ClientIpNormalizer::normalize($context->clientIp),
            path: $url['path'] ?? '',
            method: $context->method ?? '',
            referer: $headers['referer'] ?? '',
            acceptLanguage: $context->acceptLanguage ?? '',
            requestCountry: $context->requestCountry,
            requestAsn: $context->requestAsn,
            tlsFingerprint: $context->tlsFingerprint,
            hasToken: $decision->hasToken,
            tokenOutcome: $decision->tokenOutcome,
            finalAction: $decision->finalAction,
            enforcementMode: $this->enforcementModeToWire($decision->enforcementMode),
            signatureAgent: $headers['signature-agent'] ?? null,
            signatureInput: $headers['signature-input'] ?? null,
            signature: $headers['signature'] ?? null,
            // --- Capture v2: portable header signals ---
            secFetchMode: $headers['sec-fetch-mode'] ?? null,
            secFetchSite: $headers['sec-fetch-site'] ?? null,
            secFetchDest: $headers['sec-fetch-dest'] ?? null,
            secFetchUser: $headers['sec-fetch-user'] ?? null,
            secChUa: $this->truncate($context->secChUa ?? ($headers['sec-ch-ua'] ?? null)),
            secChUaMobile: $headers['sec-ch-ua-mobile'] ?? null,
            secChUaPlatform: $headers['sec-ch-ua-platform'] ?? null,
            accept: $this->truncate($context->accept ?? ($headers['accept'] ?? null)),
            // Prefer the Host header; fall back to the parsed URL host.
            host: $headers['host'] ?? $this->urlHost($url),
            hasCookies: isset($headers['cookie']),
            headerNames: $this->collectHeaderNames($context->headers),
            // Query-string derived signals (raw query never stored).
            queryLength: $query['query_length'],
            queryParamCount: $query['query_param_count'],
            querySuspicious: $query['query_suspicious'],
            // --- Capture v2: CDN plumbing (injection-only at a PHP origin) ---
            acceptEncoding: $cdn?->acceptEncoding,
            httpProtocol: $cdn?->httpProtocol,
            tlsVersion: $cdn?->tlsVersion,
            tlsCipher: $cdn?->tlsCipher,
            tlsClientHelloLength: $cdn?->tlsClientHelloLength,
            tlsClientExtensionsSha1: $cdn?->tlsClientExtensionsSha1,
            asOrganization: $this->truncate($cdn?->asOrganization),
            clientTcpRtt: $cdn?->clientTcpRtt,
            cdnVerifiedBotCategory: $cdn?->cdnVerifiedBotCategory,
            requestPriority: $cdn?->requestPriority,
            tlsFingerprintJa4: $cdn?->tlsFingerprintJa4,
        );
    }

    /**
     * @param  array<string, string>  $headers  Lowercase-keyed headers from build()
     */
    private function resolveRequestId(RequestContext $context, array $headers): string
    {
        if ($context->requestId !== null && $context->requestId !== '') {
            return $context->requestId;
        }

        $headerId = $headers['x-request-id'] ?? null;
        if (is_string($headerId) && $headerId !== '') {
            return $headerId;
        }

        return self::generateUuidV4();
    }

    /**
     * Parse a URL into components, or null when it is not an absolute URL (no
     * host) — mirroring the edge SDK's `new URL()` throwing on relative/garbage
     * input. parse_url() also returns false for malformed URLs.
     *
     * @return array<string, mixed>|null
     */
    private function safeUrl(string $url): ?array
    {
        $parsed = parse_url($url);

        if ($parsed === false || ! isset($parsed['host'])) {
            return null;
        }

        return $parsed;
    }

    /**
     * @param  array<string, mixed>|null  $url  Parsed URL components from safeUrl()
     */
    private function urlHost(?array $url): ?string
    {
        if ($url === null || ! isset($url['host'])) {
            return null;
        }

        $host = (string) $url['host'];

        return isset($url['port']) ? $host . ':' . $url['port'] : $host;
    }

    /**
     * Mechanical query-string signals. The raw query is never stored; only its
     * length, parameter count, and a coarse exploit-marker flag are emitted.
     *
     * @param  array<string, mixed>|null  $url  Parsed URL components from safeUrl()
     * @return array{query_length: int|null, query_param_count: int|null, query_suspicious: bool|null}
     */
    private function querySignals(?array $url): array
    {
        if ($url === null) {
            return ['query_length' => null, 'query_param_count' => null, 'query_suspicious' => null];
        }

        // parse_url's "query" component already has the leading "?" stripped.
        $raw = isset($url['query']) ? (string) $url['query'] : '';
        $params = $raw === '' ? [] : array_filter(explode('&', $raw), static fn (string $p): bool => $p !== '');

        // rawurldecode (not urldecode) to match decodeURIComponent: "+" stays a
        // literal plus rather than becoming a space.
        $haystack = strtolower($raw) . "\n" . strtolower(rawurldecode($raw));
        $suspicious = false;
        foreach (self::SUSPICIOUS_QUERY_MARKERS as $marker) {
            if (str_contains($haystack, $marker)) {
                $suspicious = true;
                break;
            }
        }

        return [
            'query_length' => strlen($raw),
            'query_param_count' => count($params),
            'query_suspicious' => $suspicious,
        ];
    }

    /**
     * Lowercased, deduped, sorted request-header names with edge-injected
     * headers stripped. Returns [] when no client headers are present.
     *
     * @param  array<string, string>  $headers  Raw context headers (any casing)
     * @return list<string>
     */
    private function collectHeaderNames(array $headers): array
    {
        $names = [];
        foreach (array_keys($headers) as $key) {
            if (! is_string($key)) {
                continue;
            }
            $name = strtolower($key);
            if ($this->isEdgeHeader($name)) {
                continue;
            }
            $names[$name] = true;
        }

        $names = array_keys($names);
        sort($names);

        return $names;
    }

    private function isEdgeHeader(string $name): bool
    {
        if (in_array($name, self::EDGE_HEADER_NAMES, true)) {
            return true;
        }
        foreach (self::EDGE_HEADER_PREFIXES as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Cap a free-form string to a byte budget. Byte-based (no ext-mbstring
     * dependency) but UTF-8-safe: a cut that lands inside a multibyte sequence
     * is walked back so the result is never invalid UTF-8 (which would break
     * json_encode of the event). Relies only on ext-pcre, always available.
     */
    private function truncate(?string $value, int $max = self::MAX_FIELD_LENGTH): ?string
    {
        if ($value === null || strlen($value) <= $max) {
            return $value;
        }

        $cut = substr($value, 0, $max);
        // Drop trailing bytes until the slice is valid UTF-8 (at most 3 bytes).
        while ($cut !== '' && preg_match('//u', $cut) !== 1) {
            $cut = substr($cut, 0, -1);
        }

        return $cut;
    }

    private function enforcementModeToWire(EnforcementMode $mode): string
    {
        return match ($mode) {
            EnforcementMode::DISABLED => 'disabled',
            EnforcementMode::OBSERVE => 'observe',
            EnforcementMode::ENFORCE => 'enforce',
        };
    }

    private static function generateUuidV4(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
