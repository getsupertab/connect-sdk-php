<?php

declare(strict_types=1);

namespace Supertab\Connect\Tests\Analytics;

use PHPUnit\Framework\TestCase;
use Supertab\Connect\Analytics\AnalyticsEvent;
use Supertab\Connect\Analytics\AnalyticsEventFactory;
use Supertab\Connect\Analytics\CallbackAnalyticsTransport;
use Supertab\Connect\Analytics\Decision;
use Supertab\Connect\Analytics\Enum\FinalAction;
use Supertab\Connect\Analytics\Enum\TokenOutcome;
use Supertab\Connect\Enum\EnforcementMode;
use Supertab\Connect\Http\RequestContext;

final class CallbackAnalyticsTransportTest extends TestCase
{
    private function event(): AnalyticsEvent
    {
        return (new AnalyticsEventFactory)->build(
            new RequestContext(url: 'https://example.com/article'),
            new Decision(
                hasToken: false,
                tokenOutcome: TokenOutcome::ABSENT,
                finalAction: FinalAction::ALLOW,
                enforcementMode: EnforcementMode::OBSERVE,
            ),
        );
    }

    public function test_invokes_callback_with_the_event(): void
    {
        $received = null;
        $transport = new CallbackAnalyticsTransport(function (AnalyticsEvent $event) use (&$received): void {
            $received = $event;
        });

        $event = $this->event();
        $transport->emit($event);

        $this->assertSame($event, $received);
    }

    public function test_is_fail_open_when_callback_throws(): void
    {
        $transport = new CallbackAnalyticsTransport(function (): void {
            throw new \RuntimeException('boom');
        });

        $transport->emit($this->event());

        $this->assertTrue(true); // reached without throwing
    }
}
