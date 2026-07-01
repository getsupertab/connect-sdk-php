<?php

declare(strict_types=1);

namespace Supertab\Connect\Tests;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
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

    private function shouldForceSyncOf(SupertabConnect $stc): bool
    {
        return (new ReflectionMethod(SupertabConnect::class, 'shouldForceSyncAnalytics'))->invoke($stc);
    }

    private function deferralAvailableOf(DeferredAnalyticsTransport $transport): bool
    {
        return (new ReflectionProperty(DeferredAnalyticsTransport::class, 'deferralAvailable'))->getValue($transport);
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

    public function test_force_sync_is_off_when_constant_absent(): void
    {
        // No constant defined in this (shared) process → decision is false.
        $stc = new SupertabConnect(apiKey: 'test-key');

        $this->assertFalse($this->shouldForceSyncOf($stc));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_force_sync_is_on_when_constant_defined_truthy(): void
    {
        define('SUPERTAB_CONNECT_FORCE_SYNC_ANALYTICS', true);

        $stc = new SupertabConnect(apiKey: 'test-key');

        $this->assertTrue($this->shouldForceSyncOf($stc));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_force_sync_is_off_when_constant_defined_false(): void
    {
        define('SUPERTAB_CONNECT_FORCE_SYNC_ANALYTICS', false);

        $stc = new SupertabConnect(apiKey: 'test-key');

        $this->assertFalse($this->shouldForceSyncOf($stc));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_force_sync_is_off_when_constant_defined_zero_string(): void
    {
        define('SUPERTAB_CONNECT_FORCE_SYNC_ANALYTICS', '0');

        $stc = new SupertabConnect(apiKey: 'test-key');

        $this->assertFalse($this->shouldForceSyncOf($stc));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_constant_builds_default_transport_with_deferral_off(): void
    {
        define('SUPERTAB_CONNECT_FORCE_SYNC_ANALYTICS', true);

        $stc = new SupertabConnect(apiKey: 'test-key', analyticsEnabled: true);

        $transport = $this->transportOf($stc);
        $this->assertInstanceOf(DeferredAnalyticsTransport::class, $transport);
        $this->assertInstanceOf(HttpAnalyticsTransport::class, $this->innerOf($transport));
        $this->assertFalse($this->deferralAvailableOf($transport));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_forced_sync_logs_notice_when_debug_enabled(): void
    {
        define('SUPERTAB_CONNECT_FORCE_SYNC_ANALYTICS', true);
        $logFile = tempnam(sys_get_temp_dir(), 'stc-log-');
        ini_set('error_log', $logFile);

        new SupertabConnect(apiKey: 'test-key', debug: true, analyticsEnabled: true);

        $contents = file_get_contents($logFile);
        unlink($logFile);
        $this->assertStringContainsString('SUPERTAB_CONNECT_FORCE_SYNC_ANALYTICS', $contents);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_forced_sync_does_not_log_when_debug_disabled(): void
    {
        define('SUPERTAB_CONNECT_FORCE_SYNC_ANALYTICS', true);
        $logFile = tempnam(sys_get_temp_dir(), 'stc-log-');
        ini_set('error_log', $logFile);

        new SupertabConnect(apiKey: 'test-key', debug: false, analyticsEnabled: true);

        $contents = file_get_contents($logFile);
        unlink($logFile);
        $this->assertStringNotContainsString('SUPERTAB_CONNECT_FORCE_SYNC_ANALYTICS', $contents);
    }
}
