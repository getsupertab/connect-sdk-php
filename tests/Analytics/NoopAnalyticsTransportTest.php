<?php

declare(strict_types=1);

namespace Supertab\Connect\Tests\Analytics;

use PHPUnit\Framework\TestCase;
use Supertab\Connect\Analytics\AnalyticsEventFactory;
use Supertab\Connect\Analytics\AnalyticsTransportInterface;
use Supertab\Connect\Analytics\Decision;
use Supertab\Connect\Analytics\Enum\FinalAction;
use Supertab\Connect\Analytics\Enum\TokenOutcome;
use Supertab\Connect\Analytics\NoopAnalyticsTransport;
use Supertab\Connect\Enum\EnforcementMode;
use Supertab\Connect\Http\RequestContext;

final class NoopAnalyticsTransportTest extends TestCase
{
    public function test_emit_is_a_noop_and_does_not_throw(): void
    {
        $transport = new NoopAnalyticsTransport;

        $event = (new AnalyticsEventFactory)->build(
            new RequestContext(url: 'https://example.com/'),
            new Decision(
                hasToken: false,
                tokenOutcome: TokenOutcome::ABSENT,
                finalAction: FinalAction::ALLOW,
                enforcementMode: EnforcementMode::OBSERVE,
            ),
        );

        $transport->emit($event);

        $this->assertInstanceOf(AnalyticsTransportInterface::class, $transport);
    }
}
