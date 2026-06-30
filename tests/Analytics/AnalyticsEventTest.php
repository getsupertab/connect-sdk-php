<?php

declare(strict_types=1);

namespace Supertab\Connect\Tests\Analytics;

use PHPUnit\Framework\TestCase;
use Supertab\Connect\Analytics\AnalyticsEvent;
use Supertab\Connect\Analytics\AnalyticsEventFactory;
use Supertab\Connect\Analytics\Decision;
use Supertab\Connect\Analytics\Enum\FinalAction;
use Supertab\Connect\Analytics\Enum\TokenOutcome;
use Supertab\Connect\Enum\EnforcementMode;
use Supertab\Connect\Http\RequestContext;

final class AnalyticsEventTest extends TestCase
{
    public function test_from_array_round_trips_to_array(): void
    {
        $event = (new AnalyticsEventFactory)->build(
            new RequestContext(
                url: 'https://example.com/article?id=42',
                userAgent: 'TestBot/1.0',
                acceptLanguage: 'en-US',
                method: 'GET',
                clientIp: '203.0.113.1',
                headers: ['referer' => 'https://ref.example'],
            ),
            new Decision(
                hasToken: true,
                tokenOutcome: TokenOutcome::VALID,
                finalAction: FinalAction::BLOCK,
                enforcementMode: EnforcementMode::ENFORCE,
            ),
        );

        $payload = $event->toArray();

        // Reconstructing from the serialized payload yields an identical payload —
        // this is the contract a queued job (e.g. Action Scheduler) relies on.
        $this->assertSame($payload, AnalyticsEvent::fromArray($payload)->toArray());
    }

    public function test_from_array_reconstructs_enums(): void
    {
        $event = AnalyticsEvent::fromArray([
            'request_id' => 'req-1',
            'token_outcome' => 'valid',
            'final_action' => 'block',
        ]);

        $this->assertSame(TokenOutcome::VALID, $event->tokenOutcome);
        $this->assertSame(FinalAction::BLOCK, $event->finalAction);
    }

    public function test_from_array_tolerates_missing_keys_with_safe_defaults(): void
    {
        $event = AnalyticsEvent::fromArray([
            'request_id' => 'req-1',
            'token_outcome' => 'absent',
            'final_action' => 'allow',
        ]);

        $this->assertSame('req-1', $event->requestId);
        $this->assertSame('', $event->userAgent);
        $this->assertSame('', $event->path);
        $this->assertSame([], $event->headerNames);
        $this->assertFalse($event->hasToken);
        $this->assertFalse($event->hasCookies);
        $this->assertNull($event->sourceCdn);
        $this->assertNull($event->requestAsn);
    }
}
