<?php

declare(strict_types=1);

namespace Supertab\Connect\Tests\Analytics;

use PHPUnit\Framework\TestCase;
use Supertab\Connect\Analytics\AnalyticsEvent;
use Supertab\Connect\Analytics\AnalyticsEventFactory;
use Supertab\Connect\Analytics\AnalyticsTransportInterface;
use Supertab\Connect\Analytics\Decision;
use Supertab\Connect\Analytics\DeferredAnalyticsTransport;
use Supertab\Connect\Analytics\Enum\FinalAction;
use Supertab\Connect\Analytics\Enum\TokenOutcome;
use Supertab\Connect\Enum\EnforcementMode;
use Supertab\Connect\Http\RequestContext;

final class DeferredAnalyticsTransportTest extends TestCase
{
    private function event(string $path = '/'): AnalyticsEvent
    {
        return (new AnalyticsEventFactory)->build(
            new RequestContext(url: 'https://example.com' . $path),
            new Decision(
                hasToken: false,
                tokenOutcome: TokenOutcome::ABSENT,
                finalAction: FinalAction::ALLOW,
                enforcementMode: EnforcementMode::OBSERVE,
            ),
        );
    }

    /** Records every event passed to emit(), in order. */
    private function recordingTransport(): AnalyticsTransportInterface
    {
        return new class implements AnalyticsTransportInterface {
            /** @var list<AnalyticsEvent> */
            public array $emitted = [];

            public function emit(AnalyticsEvent $event): void
            {
                $this->emitted[] = $event;
            }
        };
    }

    private function throwingTransport(): AnalyticsTransportInterface
    {
        return new class implements AnalyticsTransportInterface {
            public function emit(AnalyticsEvent $event): void
            {
                throw new \RuntimeException('boom');
            }
        };
    }

    public function test_emits_synchronously_when_deferral_unavailable(): void
    {
        $inner = $this->recordingTransport();
        $transport = new DeferredAnalyticsTransport(inner: $inner, deferralAvailable: false);

        $transport->emit($this->event('/a'));

        $this->assertCount(1, $inner->emitted);
    }

    public function test_defaults_to_synchronous_without_fastcgi_finish_request(): void
    {
        // Under CLI (and any SAPI lacking fastcgi_finish_request) the default
        // detection must pick the synchronous path: emit() delivers immediately.
        $inner = $this->recordingTransport();
        $transport = new DeferredAnalyticsTransport(inner: $inner);

        $transport->emit($this->event('/a'));

        $this->assertCount(1, $inner->emitted);
    }

    public function test_defers_emission_until_flush_when_available(): void
    {
        $inner = $this->recordingTransport();
        $captured = null;
        $schedule = function (callable $flush) use (&$captured): void {
            $captured = $flush;
        };

        $transport = new DeferredAnalyticsTransport(
            inner: $inner,
            deferralAvailable: true,
            scheduleFlush: $schedule,
        );

        $transport->emit($this->event('/a'));
        $transport->emit($this->event('/b'));

        // Nothing delivered yet — events are buffered for the shutdown flush.
        $this->assertCount(0, $inner->emitted);
        $this->assertNotNull($captured);

        // Simulate the request-shutdown flush.
        ($captured)();

        $this->assertCount(2, $inner->emitted);
        $this->assertSame('/a', $inner->emitted[0]->path);
        $this->assertSame('/b', $inner->emitted[1]->path);
    }

    public function test_registers_flush_only_once_for_multiple_events(): void
    {
        $calls = 0;
        $schedule = function (callable $flush) use (&$calls): void {
            $calls++;
        };

        $transport = new DeferredAnalyticsTransport(
            inner: $this->recordingTransport(),
            deferralAvailable: true,
            scheduleFlush: $schedule,
        );

        $transport->emit($this->event('/a'));
        $transport->emit($this->event('/b'));
        $transport->emit($this->event('/c'));

        $this->assertSame(1, $calls);
    }

    public function test_flush_drains_buffer_and_is_idempotent(): void
    {
        $inner = $this->recordingTransport();
        $transport = new DeferredAnalyticsTransport(
            inner: $inner,
            deferralAvailable: true,
            scheduleFlush: fn (callable $flush) => null, // never auto-run
        );

        $transport->emit($this->event('/a'));
        $this->assertCount(0, $inner->emitted);

        $transport->flush();
        $transport->flush();

        $this->assertCount(1, $inner->emitted);
    }

    public function test_synchronous_emit_is_fail_open_when_inner_throws(): void
    {
        $transport = new DeferredAnalyticsTransport(
            inner: $this->throwingTransport(),
            deferralAvailable: false,
        );

        $transport->emit($this->event('/a'));

        $this->assertTrue(true); // reached without throwing
    }

    public function test_deferred_flush_is_fail_open_when_inner_throws(): void
    {
        $transport = new DeferredAnalyticsTransport(
            inner: $this->throwingTransport(),
            deferralAvailable: true,
            scheduleFlush: fn (callable $flush) => null,
        );

        $transport->emit($this->event('/a'));
        $transport->flush();

        $this->assertTrue(true); // reached without throwing
    }
}
