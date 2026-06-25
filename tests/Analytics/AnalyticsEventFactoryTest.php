<?php

declare(strict_types=1);

namespace Supertab\Connect\Tests\Analytics;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Supertab\Connect\Analytics\AnalyticsEventFactory;
use Supertab\Connect\Analytics\CdnRequestSignals;
use Supertab\Connect\Analytics\Decision;
use Supertab\Connect\Analytics\Enum\FinalAction;
use Supertab\Connect\Analytics\Enum\TokenOutcome;
use Supertab\Connect\Enum\EnforcementMode;
use Supertab\Connect\Http\RequestContext;

final class AnalyticsEventFactoryTest extends TestCase
{
    private function decision(
        bool $hasToken = false,
        TokenOutcome $tokenOutcome = TokenOutcome::ABSENT,
        FinalAction $finalAction = FinalAction::ALLOW,
        EnforcementMode $enforcementMode = EnforcementMode::OBSERVE,
    ): Decision {
        return new Decision(
            hasToken: $hasToken,
            tokenOutcome: $tokenOutcome,
            finalAction: $finalAction,
            enforcementMode: $enforcementMode,
        );
    }

    public function test_builds_core_request_fields(): void
    {
        $ctx = new RequestContext(
            url: 'https://example.com/premium/article?ref=x',
            userAgent: 'TestBot/1.0',
            acceptLanguage: 'en-US',
            headers: ['referer' => 'https://google.com/'],
            method: 'GET',
            clientIp: '203.0.113.1',
        );

        $event = (new AnalyticsEventFactory)->build($ctx, $this->decision());
        $payload = $event->toArray();

        $this->assertSame('TestBot/1.0', $payload['user_agent']);
        $this->assertSame('::ffff:203.0.113.1', $payload['client_ip']);
        $this->assertSame('/premium/article', $payload['path']);
        $this->assertSame('GET', $payload['method']);
        $this->assertSame('https://google.com/', $payload['referer']);
        $this->assertSame('en-US', $payload['accept_language']);
    }

    public function test_includes_schema_version_and_null_source_cdn_by_default(): void
    {
        // No CDN in front of the origin SDK → source_cdn is emitted as null
        // (the relay accepts a null source_cdn for SDK-originated events). The
        // key is still present in the payload.
        $event = (new AnalyticsEventFactory)->build(new RequestContext(url: 'https://example.com/'), $this->decision());
        $payload = $event->toArray();

        $this->assertSame(2, $payload['schema_version']);
        $this->assertArrayHasKey('source_cdn', $payload);
        $this->assertNull($payload['source_cdn']);
    }

    public function test_source_cdn_is_configurable(): void
    {
        $event = (new AnalyticsEventFactory('cloudflare'))->build(new RequestContext(url: 'https://example.com/'), $this->decision());

        $this->assertSame('cloudflare', $event->toArray()['source_cdn']);
    }

    public function test_maps_decision_fields(): void
    {
        $decision = $this->decision(
            hasToken: true,
            tokenOutcome: TokenOutcome::VALID,
            finalAction: FinalAction::ALLOW,
            enforcementMode: EnforcementMode::OBSERVE,
        );

        $payload = (new AnalyticsEventFactory)->build(new RequestContext(url: 'https://example.com/'), $decision)->toArray();

        $this->assertTrue($payload['has_token']);
        $this->assertSame('valid', $payload['token_outcome']);
        $this->assertSame('allow', $payload['final_action']);
        $this->assertSame('observe', $payload['enforcement_mode']);
    }

    #[DataProvider('enforcementWireProvider')]
    public function test_enforcement_mode_wire_mapping(EnforcementMode $mode, string $expected): void
    {
        $payload = (new AnalyticsEventFactory)->build(
            new RequestContext(url: 'https://example.com/'),
            $this->decision(enforcementMode: $mode),
        )->toArray();

        $this->assertSame($expected, $payload['enforcement_mode']);
    }

    /**
     * @return array<string, array{EnforcementMode, string}>
     */
    public static function enforcementWireProvider(): array
    {
        return [
            'disabled' => [EnforcementMode::DISABLED, 'disabled'],
            'observe' => [EnforcementMode::OBSERVE, 'observe'],
            'enforce' => [EnforcementMode::ENFORCE, 'enforce'],
        ];
    }

    public function test_nullable_signals_default_to_null(): void
    {
        $payload = (new AnalyticsEventFactory)->build(new RequestContext(url: 'https://example.com/'), $this->decision())->toArray();

        $this->assertNull($payload['request_country']);
        $this->assertNull($payload['request_asn']);
        $this->assertNull($payload['tls_fingerprint']);
    }

    public function test_explicit_signals_are_passed_through(): void
    {
        $ctx = new RequestContext(
            url: 'https://example.com/',
            requestCountry: 'DE',
            requestAsn: 13335,
            tlsFingerprint: 'ja3hash',
        );

        $payload = (new AnalyticsEventFactory)->build($ctx, $this->decision())->toArray();

        $this->assertSame('DE', $payload['request_country']);
        $this->assertSame(13335, $payload['request_asn']);
        $this->assertSame('ja3hash', $payload['tls_fingerprint']);
    }

    public function test_signature_headers_extracted_from_headers(): void
    {
        $ctx = new RequestContext(
            url: 'https://example.com/',
            headers: [
                'signature-agent' => 'https://bot.example',
                'signature-input' => 'sig1=(...)',
                'signature' => 'sig1=:abc:',
            ],
        );

        $payload = (new AnalyticsEventFactory)->build($ctx, $this->decision())->toArray();

        $this->assertSame('https://bot.example', $payload['signature_agent']);
        $this->assertSame('sig1=(...)', $payload['signature_input']);
        $this->assertSame('sig1=:abc:', $payload['signature']);
    }

    public function test_header_lookups_are_case_insensitive(): void
    {
        // Manually constructed contexts (frameworks, getallheaders()) carry
        // original header casing; the documented contract is that keys are
        // normalized when converted, not by the caller.
        $ctx = new RequestContext(
            url: 'https://example.com/',
            headers: [
                'Referer' => 'https://google.com/',
                'X-Request-Id' => 'header-id',
                'Signature-Agent' => 'https://bot.example',
                'Signature-Input' => 'sig1=(...)',
                'Signature' => 'sig1=:abc:',
            ],
        );

        $payload = (new AnalyticsEventFactory)->build($ctx, $this->decision())->toArray();

        $this->assertSame('https://google.com/', $payload['referer']);
        $this->assertSame('header-id', $payload['request_id']);
        $this->assertSame('https://bot.example', $payload['signature_agent']);
        $this->assertSame('sig1=(...)', $payload['signature_input']);
        $this->assertSame('sig1=:abc:', $payload['signature']);
    }

    public function test_signature_headers_null_when_absent(): void
    {
        $payload = (new AnalyticsEventFactory)->build(new RequestContext(url: 'https://example.com/'), $this->decision())->toArray();

        $this->assertNull($payload['signature_agent']);
        $this->assertNull($payload['signature_input']);
        $this->assertNull($payload['signature']);
    }

    public function test_request_id_prefers_explicit_value(): void
    {
        $ctx = new RequestContext(
            url: 'https://example.com/',
            requestId: 'explicit-id',
            headers: ['x-request-id' => 'header-id'],
        );

        $payload = (new AnalyticsEventFactory)->build($ctx, $this->decision())->toArray();

        $this->assertSame('explicit-id', $payload['request_id']);
    }

    public function test_request_id_falls_back_to_header(): void
    {
        $ctx = new RequestContext(
            url: 'https://example.com/',
            headers: ['x-request-id' => 'header-id'],
        );

        $payload = (new AnalyticsEventFactory)->build($ctx, $this->decision())->toArray();

        $this->assertSame('header-id', $payload['request_id']);
    }

    public function test_request_id_generates_uuid_when_absent(): void
    {
        $payload = (new AnalyticsEventFactory)->build(new RequestContext(url: 'https://example.com/'), $this->decision())->toArray();

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $payload['request_id'],
        );
    }

    public function test_timestamp_uses_provided_time_in_utc_iso_with_millis(): void
    {
        $now = new DateTimeImmutable('2026-06-09T12:34:56.789000', new DateTimeZone('UTC'));

        $payload = (new AnalyticsEventFactory)->build(
            new RequestContext(url: 'https://example.com/'),
            $this->decision(),
            $now,
        )->toArray();

        $this->assertSame('2026-06-09T12:34:56.789Z', $payload['timestamp']);
    }

    public function test_timestamp_default_is_iso_utc(): void
    {
        $payload = (new AnalyticsEventFactory)->build(new RequestContext(url: 'https://example.com/'), $this->decision())->toArray();

        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/',
            $payload['timestamp'],
        );
    }

    public function test_missing_optional_strings_default_to_empty(): void
    {
        $payload = (new AnalyticsEventFactory)->build(new RequestContext(url: 'https://example.com/path'), $this->decision())->toArray();

        $this->assertSame('', $payload['user_agent']);
        $this->assertSame('', $payload['method']);
        $this->assertSame('', $payload['referer']);
        $this->assertSame('', $payload['accept_language']);
        $this->assertSame('::', $payload['client_ip']);
    }

    public function test_path_is_empty_for_unparseable_url(): void
    {
        $payload = (new AnalyticsEventFactory)->build(new RequestContext(url: ''), $this->decision())->toArray();

        $this->assertSame('', $payload['path']);
    }

    // --- Capture v2 (schema_version 2) signals ---

    public function test_capture_v2_signals_default_to_null_when_absent(): void
    {
        $payload = (new AnalyticsEventFactory)->build(new RequestContext(url: 'https://example.com/'), $this->decision())->toArray();

        $this->assertSame(2, $payload['schema_version']);

        // Portable header signals — none sent.
        foreach (
            ['sec_fetch_mode', 'sec_fetch_site', 'sec_fetch_dest', 'sec_fetch_user',
            'sec_ch_ua', 'sec_ch_ua_mobile', 'sec_ch_ua_platform', 'accept'] as $key
        ) {
            $this->assertArrayHasKey($key, $payload);
            $this->assertNull($payload[$key], "$key should default to null");
        }
        $this->assertFalse($payload['has_cookies']);
        $this->assertSame([], $payload['header_names']);

        // Host falls back to the URL when no Host header is present.
        $this->assertSame('example.com', $payload['host']);

        // Query-less URL → zeroes (not null; the URL parsed fine).
        $this->assertSame(0, $payload['query_length']);
        $this->assertSame(0, $payload['query_param_count']);
        $this->assertFalse($payload['query_suspicious']);

        // CDN plumbing — no cdnSignals in context → all null.
        foreach (
            ['accept_encoding', 'http_protocol', 'tls_version', 'tls_cipher',
            'tls_client_hello_length', 'tls_client_extensions_sha1', 'as_organization',
            'client_tcp_rtt', 'cdn_verified_bot_category', 'request_priority',
            'tls_fingerprint_ja4'] as $key
        ) {
            $this->assertArrayHasKey($key, $payload);
            $this->assertNull($payload[$key], "$key should default to null");
        }
    }

    public function test_captures_sec_fetch_and_client_hints_from_browser_request(): void
    {
        $ctx = new RequestContext(
            url: 'https://example.com/',
            accept: 'text/html',
            secChUa: '"Chromium";v="120", "Not(A:Brand";v="24"',
            headers: [
                'sec-fetch-mode' => 'navigate',
                'sec-fetch-site' => 'none',
                'sec-fetch-dest' => 'document',
                'sec-fetch-user' => '?1',
                'sec-ch-ua-mobile' => '?0',
                'sec-ch-ua-platform' => '"macOS"',
                'cookie' => 'session=abc',
            ],
        );

        $payload = (new AnalyticsEventFactory)->build($ctx, $this->decision())->toArray();

        $this->assertSame('navigate', $payload['sec_fetch_mode']);
        $this->assertSame('none', $payload['sec_fetch_site']);
        $this->assertSame('document', $payload['sec_fetch_dest']);
        $this->assertSame('?1', $payload['sec_fetch_user']);
        $this->assertSame('"Chromium";v="120", "Not(A:Brand";v="24"', $payload['sec_ch_ua']);
        $this->assertSame('?0', $payload['sec_ch_ua_mobile']);
        $this->assertSame('"macOS"', $payload['sec_ch_ua_platform']);
        $this->assertSame('text/html', $payload['accept']);
        $this->assertTrue($payload['has_cookies']);
    }

    public function test_automated_client_carries_no_browser_signals(): void
    {
        $ctx = new RequestContext(url: 'https://example.com/', userAgent: 'curl/8.0');

        $payload = (new AnalyticsEventFactory)->build($ctx, $this->decision())->toArray();

        $this->assertNull($payload['sec_fetch_mode']);
        $this->assertNull($payload['sec_fetch_site']);
        $this->assertNull($payload['sec_fetch_dest']);
        $this->assertNull($payload['sec_fetch_user']);
        $this->assertNull($payload['sec_ch_ua']);
        $this->assertNull($payload['sec_ch_ua_mobile']);
        $this->assertNull($payload['sec_ch_ua_platform']);
        $this->assertNull($payload['accept']);
        $this->assertFalse($payload['has_cookies']);
    }

    public function test_host_falls_back_to_url_host(): void
    {
        $payload = (new AnalyticsEventFactory)->build(
            new RequestContext(url: 'https://pub.example.com/a'),
            $this->decision(),
        )->toArray();

        $this->assertSame('pub.example.com', $payload['host']);
    }

    public function test_host_prefers_header_over_url(): void
    {
        $ctx = new RequestContext(
            url: 'https://origin.internal/a',
            headers: ['host' => 'pub.example.com'],
        );

        $payload = (new AnalyticsEventFactory)->build($ctx, $this->decision())->toArray();

        $this->assertSame('pub.example.com', $payload['host']);
    }

    public function test_truncates_accept_and_sec_ch_ua_to_512_chars(): void
    {
        $long = str_repeat('a', 600);
        $ctx = new RequestContext(url: 'https://example.com/', accept: $long, secChUa: $long);

        $payload = (new AnalyticsEventFactory)->build($ctx, $this->decision())->toArray();

        $this->assertSame(512, strlen($payload['accept']));
        $this->assertSame(512, strlen($payload['sec_ch_ua']));
    }

    public function test_header_names_lowercased_deduped_and_sorted(): void
    {
        $ctx = new RequestContext(
            url: 'https://example.com/',
            headers: ['User-Agent' => 'x', 'Accept' => 'y', 'Referer' => 'z'],
        );

        $payload = (new AnalyticsEventFactory)->build($ctx, $this->decision())->toArray();

        $this->assertSame(['accept', 'referer', 'user-agent'], $payload['header_names']);
    }

    public function test_header_names_strips_edge_injected_headers(): void
    {
        $ctx = new RequestContext(
            url: 'https://example.com/',
            headers: [
                'user-agent' => 'x',
                // Cloudflare
                'cf-connecting-ip' => '1.2.3.4',
                'cf-ray' => 'abc',
                // Fastly
                'fastly-client-ip' => '1.2.3.4',
                'fastly-client-ja3' => 'deadbeef',
                // CloudFront
                'cloudfront-viewer-country' => 'DE',
                'cloudfront-viewer-ja3-fingerprint' => 'abc',
                // shared / SDK routing
                'x-forwarded-for' => '1.2.3.4',
                'x-real-ip' => '1.2.3.4',
                'x-original-request-url' => 'https://pub.example.com/a',
            ],
        );

        $payload = (new AnalyticsEventFactory)->build($ctx, $this->decision())->toArray();

        $this->assertSame(['user-agent'], $payload['header_names']);
    }

    public function test_query_signals_derive_without_storing_raw_query(): void
    {
        $payload = (new AnalyticsEventFactory)->build(
            new RequestContext(url: 'https://x.test/p?a=1&b=2&c=3'),
            $this->decision(),
        )->toArray();

        $this->assertSame(strlen('a=1&b=2&c=3'), $payload['query_length']);
        $this->assertSame(3, $payload['query_param_count']);
        $this->assertFalse($payload['query_suspicious']);

        // The raw query string must never appear on the event.
        $this->assertStringNotContainsString('a=1&b=2&c=3', json_encode($payload));
    }

    public function test_query_signals_are_zero_for_query_less_url(): void
    {
        $payload = (new AnalyticsEventFactory)->build(
            new RequestContext(url: 'https://x.test/p'),
            $this->decision(),
        )->toArray();

        $this->assertSame(0, $payload['query_length']);
        $this->assertSame(0, $payload['query_param_count']);
        $this->assertFalse($payload['query_suspicious']);
    }

    #[DataProvider('suspiciousQueryProvider')]
    public function test_query_suspicious_flags_exploit_markers(string $url): void
    {
        $payload = (new AnalyticsEventFactory)->build(new RequestContext(url: $url), $this->decision())->toArray();

        $this->assertTrue($payload['query_suspicious']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function suspiciousQueryProvider(): array
    {
        return [
            'path traversal' => ['https://x.test/?f=../../etc/passwd'],
            'sql injection (url-encoded)' => ['https://x.test/?q=UNION%20SELECT%201'],
            'xss (url-encoded)' => ['https://x.test/?x=%3Cscript%3E'],
        ];
    }

    public function test_query_signals_null_for_unparseable_url(): void
    {
        $payload = (new AnalyticsEventFactory)->build(new RequestContext(url: 'not a url'), $this->decision())->toArray();

        $this->assertNull($payload['query_length']);
        $this->assertNull($payload['query_param_count']);
        $this->assertNull($payload['query_suspicious']);
    }

    public function test_cdn_signals_pass_through_and_truncate_as_organization(): void
    {
        $ctx = new RequestContext(
            url: 'https://example.com/',
            cdnSignals: new CdnRequestSignals(
                acceptEncoding: 'gzip, br',
                httpProtocol: 'HTTP/2',
                tlsVersion: 'TLSv1.3',
                tlsCipher: 'AEAD-AES128-GCM-SHA256',
                tlsClientHelloLength: 1811,
                tlsClientExtensionsSha1: '4cFD...',
                asOrganization: str_repeat('o', 600),
                clientTcpRtt: 50,
                cdnVerifiedBotCategory: 'Search Engine Crawler',
                requestPriority: 'weight=256;exclusive=1',
                tlsFingerprintJa4: null,
            ),
        );

        $payload = (new AnalyticsEventFactory)->build($ctx, $this->decision())->toArray();

        $this->assertSame('gzip, br', $payload['accept_encoding']);
        $this->assertSame('HTTP/2', $payload['http_protocol']);
        $this->assertSame('TLSv1.3', $payload['tls_version']);
        $this->assertSame('AEAD-AES128-GCM-SHA256', $payload['tls_cipher']);
        $this->assertSame(1811, $payload['tls_client_hello_length']);
        $this->assertSame('4cFD...', $payload['tls_client_extensions_sha1']);
        $this->assertSame(512, strlen($payload['as_organization']));
        $this->assertSame(50, $payload['client_tcp_rtt']);
        $this->assertSame('Search Engine Crawler', $payload['cdn_verified_bot_category']);
        $this->assertSame('weight=256;exclusive=1', $payload['request_priority']);
        $this->assertNull($payload['tls_fingerprint_ja4']);
    }
}
