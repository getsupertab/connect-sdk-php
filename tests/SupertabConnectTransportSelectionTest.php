<?php

declare(strict_types=1);

namespace Supertab\Connect\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Supertab\Connect\Analytics\AnalyticsTransportInterface;
use Supertab\Connect\Analytics\DeferredAnalyticsTransport;
use Supertab\Connect\Analytics\HttpAnalyticsTransport;
use Supertab\Connect\Analytics\NoopAnalyticsTransport;
use Supertab\Connect\SupertabConnect;

final class SupertabConnectTransportSelectionTest extends TestCase
{
    protected function setUp(): void
    {
        SupertabConnect::resetInstance();
        SupertabConnect::setBaseUrl('https://api-connect.supertab.co');
    }

    protected function tearDown(): void
    {
        SupertabConnect::resetInstance();
        SupertabConnect::setBaseUrl('https://api-connect.supertab.co');
    }

    private function transportOf(SupertabConnect $stc): AnalyticsTransportInterface
    {
        return (new ReflectionProperty(SupertabConnect::class, 'analyticsTransport'))->getValue($stc);
    }

    private function innerOf(DeferredAnalyticsTransport $transport): AnalyticsTransportInterface
    {
        return (new ReflectionProperty(DeferredAnalyticsTransport::class, 'inner'))->getValue($transport);
    }

    public function test_enabled_wraps_http_transport_in_deferred_by_default(): void
    {
        // Analytics enabled → the HTTP transport, wrapped in the deferred
        // decorator so the POST leaves the user-perceived latency path on
        // FastCGI SAPIs (and falls back to synchronous everywhere else).
        $stc = new SupertabConnect(apiKey: 'test-key', analyticsEnabled: true);

        $transport = $this->transportOf($stc);
        $this->assertInstanceOf(DeferredAnalyticsTransport::class, $transport);
        $this->assertInstanceOf(HttpAnalyticsTransport::class, $this->innerOf($transport));
    }

    public function test_disabled_uses_noop(): void
    {
        $stc = new SupertabConnect(apiKey: 'test-key', analyticsEnabled: false);

        $this->assertInstanceOf(NoopAnalyticsTransport::class, $this->transportOf($stc));
    }

    public function test_injected_transport_wins(): void
    {
        $injected = $this->createMock(AnalyticsTransportInterface::class);

        $stc = new SupertabConnect(
            apiKey: 'test-key',
            analyticsEnabled: true,
            analyticsTransport: $injected,
        );

        $this->assertSame($injected, $this->transportOf($stc));
    }
}
