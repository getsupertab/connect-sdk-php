<?php

declare(strict_types=1);

namespace Supertab\Connect\Tests\Analytics;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Supertab\Connect\Analytics\AnalyticsEventFactory;
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

        $this->assertSame(1, $payload['schema_version']);
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
}
