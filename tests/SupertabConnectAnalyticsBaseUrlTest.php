<?php

declare(strict_types=1);

namespace Supertab\Connect\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Supertab\Connect\Analytics\DeferredAnalyticsTransport;
use Supertab\Connect\Analytics\HttpAnalyticsTransport;
use Supertab\Connect\SupertabConnect;

/**
 * Analytics base URL resolution — the relay targets the dedicated ingest
 * service by default, independent of the API base URL used for token
 * acquisition / JWKS / verification. Mirrors the TypeScript SDK's
 * "analytics base URL resolution" suite.
 */
final class SupertabConnectAnalyticsBaseUrlTest extends TestCase
{
    private const DEFAULT_INGEST = 'https://ingest-connect.supertab.co';

    protected function setUp(): void
    {
        SupertabConnect::resetInstance();
        SupertabConnect::setBaseUrl('https://api-connect.supertab.co');
        SupertabConnect::setAnalyticsBaseUrl(self::DEFAULT_INGEST);
    }

    protected function tearDown(): void
    {
        SupertabConnect::resetInstance();
        SupertabConnect::setBaseUrl('https://api-connect.supertab.co');
        SupertabConnect::setAnalyticsBaseUrl(self::DEFAULT_INGEST);
    }

    /**
     * Extract the base URL wired into the default HTTP analytics transport
     * (unwrapping the deferred decorator).
     */
    private function relayBaseUrlOf(SupertabConnect $stc): string
    {
        $transport = (new ReflectionProperty(SupertabConnect::class, 'analyticsTransport'))->getValue($stc);
        $this->assertInstanceOf(DeferredAnalyticsTransport::class, $transport);

        $inner = (new ReflectionProperty(DeferredAnalyticsTransport::class, 'inner'))->getValue($transport);
        $this->assertInstanceOf(HttpAnalyticsTransport::class, $inner);

        return (new ReflectionProperty(HttpAnalyticsTransport::class, 'baseUrl'))->getValue($inner);
    }

    public function test_defaults_analytics_relay_to_ingest_host(): void
    {
        $stc = new SupertabConnect(apiKey: 'k', analyticsEnabled: true);

        $this->assertSame(self::DEFAULT_INGEST, $this->relayBaseUrlOf($stc));
    }

    public function test_constructor_analytics_base_url_overrides_default_host(): void
    {
        $stc = new SupertabConnect(
            apiKey: 'k',
            analyticsEnabled: true,
            analyticsBaseUrl: 'https://ingest.example.com',
        );

        $this->assertSame('https://ingest.example.com', $this->relayBaseUrlOf($stc));
    }

    public function test_set_analytics_base_url_overrides_default_host(): void
    {
        SupertabConnect::setAnalyticsBaseUrl('https://static.example.com');

        $stc = new SupertabConnect(apiKey: 'k', analyticsEnabled: true);

        $this->assertSame('https://static.example.com', $this->relayBaseUrlOf($stc));
    }

    public function test_constructor_analytics_base_url_beats_static_setter(): void
    {
        SupertabConnect::setAnalyticsBaseUrl('https://static.example.com');

        $stc = new SupertabConnect(
            apiKey: 'k',
            analyticsEnabled: true,
            analyticsBaseUrl: 'https://perinstance.example.com',
        );

        $this->assertSame('https://perinstance.example.com', $this->relayBaseUrlOf($stc));
    }

    public function test_analytics_host_is_independent_of_set_base_url(): void
    {
        SupertabConnect::setBaseUrl('https://api.example.com');

        $stc = new SupertabConnect(apiKey: 'k', analyticsEnabled: true);

        $this->assertSame(self::DEFAULT_INGEST, $this->relayBaseUrlOf($stc));
    }

    public function test_get_analytics_base_url_reflects_setter(): void
    {
        SupertabConnect::setAnalyticsBaseUrl('https://x.example.com');

        $this->assertSame('https://x.example.com', SupertabConnect::getAnalyticsBaseUrl());
    }

    public function test_set_analytics_base_url_trims_trailing_slash(): void
    {
        SupertabConnect::setAnalyticsBaseUrl('https://x.example.com/');

        $this->assertSame('https://x.example.com', SupertabConnect::getAnalyticsBaseUrl());
    }
}
