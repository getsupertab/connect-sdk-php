<?php

declare(strict_types=1);

namespace Supertab\Connect\Tests\Analytics;

use PHPUnit\Framework\TestCase;
use Supertab\Connect\Analytics\AnalyticsEvent;
use Supertab\Connect\Analytics\AnalyticsEventFactory;
use Supertab\Connect\Analytics\Decision;
use Supertab\Connect\Analytics\Enum\FinalAction;
use Supertab\Connect\Analytics\Enum\TokenOutcome;
use Supertab\Connect\Analytics\KeepAliveConnectionInterface;
use Supertab\Connect\Analytics\KeepAliveHttpAnalyticsTransport;
use Supertab\Connect\Enum\EnforcementMode;
use Supertab\Connect\Http\RequestContext;

final class KeepAliveHttpAnalyticsTransportTest extends TestCase
{
    private function event(): AnalyticsEvent
    {
        return (new AnalyticsEventFactory)->build(
            new RequestContext(
                url: 'https://example.com/article',
                userAgent: 'TestBot/1.0',
                method: 'GET',
                clientIp: '203.0.113.1',
            ),
            new Decision(
                hasToken: false,
                tokenOutcome: TokenOutcome::ABSENT,
                finalAction: FinalAction::ALLOW,
                enforcementMode: EnforcementMode::SOFT,
            ),
        );
    }

    public function test_posts_event_body_and_auth_header_through_connection(): void
    {
        $captured = [];
        $connection = $this->createMock(KeepAliveConnectionInterface::class);
        $connection->expects($this->once())
            ->method('post')
            ->willReturnCallback(function (string $body, array $headers) use (&$captured): int {
                $captured = ['body' => $body, 'headers' => $headers];

                return 202;
            });

        $transport = new KeepAliveHttpAnalyticsTransport(apiKey: 'test-api-key', connection: $connection);
        $transport->emit($this->event());

        $data = json_decode($captured['body'], true);
        $this->assertSame(1, $data['schema_version']);
        $this->assertNull($data['source_cdn']);
        $this->assertSame('::ffff:203.0.113.1', $data['client_ip']);
        $this->assertSame('Bearer test-api-key', $captured['headers']['Authorization']);
        $this->assertSame('application/json', $captured['headers']['Content-Type']);
    }

    public function test_reuses_the_same_connection_across_emits(): void
    {
        $connection = $this->createMock(KeepAliveConnectionInterface::class);
        // The transport holds ONE connection and posts to it repeatedly — that
        // reuse is the whole point (cURL keeps the underlying socket alive).
        $connection->expects($this->exactly(3))->method('post')->willReturn(202);

        $transport = new KeepAliveHttpAnalyticsTransport(apiKey: 'test-api-key', connection: $connection);
        $transport->emit($this->event());
        $transport->emit($this->event());
        $transport->emit($this->event());
    }

    public function test_is_fail_open_when_connection_throws(): void
    {
        $connection = $this->createMock(KeepAliveConnectionInterface::class);
        $connection->method('post')->willThrowException(new \RuntimeException('connection reset'));

        $transport = new KeepAliveHttpAnalyticsTransport(apiKey: 'test-api-key', connection: $connection);
        $transport->emit($this->event());

        $this->assertTrue(true); // reached without throwing
    }

    public function test_swallows_non_2xx_status(): void
    {
        $connection = $this->createMock(KeepAliveConnectionInterface::class);
        $connection->method('post')->willReturn(500);

        $transport = new KeepAliveHttpAnalyticsTransport(apiKey: 'test-api-key', connection: $connection);
        $transport->emit($this->event());

        $this->assertTrue(true); // reached without throwing
    }

    public function test_parse_target_resolves_https_defaults(): void
    {
        $this->assertSame(
            ['transport' => 'tls', 'host' => 'api-connect.supertab.co', 'port' => 443, 'path' => '/ingest/events'],
            KeepAliveHttpAnalyticsTransport::parseTarget('https://api-connect.supertab.co/ingest/events'),
        );
    }

    public function test_parse_target_resolves_http_and_explicit_port(): void
    {
        $this->assertSame(
            ['transport' => 'tcp', 'host' => '127.0.0.1', 'port' => 8080, 'path' => '/ingest/events'],
            KeepAliveHttpAnalyticsTransport::parseTarget('http://127.0.0.1:8080/ingest/events'),
        );
    }

    public function test_adaptive_builds_a_transport(): void
    {
        $transport = KeepAliveHttpAnalyticsTransport::adaptive('key', 'https://api-connect.supertab.co', 1);

        $this->assertInstanceOf(KeepAliveHttpAnalyticsTransport::class, $transport);
    }
}
