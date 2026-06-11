<?php

declare(strict_types=1);

namespace Supertab\Connect\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Supertab\Connect\Analytics\AnalyticsTransportInterface;
use Supertab\Connect\Analytics\KeepAliveHttpAnalyticsTransport;
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

    public function test_enabled_uses_adaptive_transport_by_default(): void
    {
        // Connection reuse is on by default → the adaptive (persistent + cURL
        // fallback) transport, which is a KeepAliveHttpAnalyticsTransport.
        $stc = new SupertabConnect(apiKey: 'test-key', analyticsEnabled: true);

        $this->assertInstanceOf(KeepAliveHttpAnalyticsTransport::class, $this->transportOf($stc));
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
