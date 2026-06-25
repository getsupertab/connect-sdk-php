<?php

declare(strict_types=1);

namespace Supertab\Connect\Tests\Analytics;

use PHPUnit\Framework\TestCase;
use Supertab\Connect\Analytics\AnalyticsEvent;
use Supertab\Connect\Analytics\AnalyticsEventFactory;
use Supertab\Connect\Analytics\Decision;
use Supertab\Connect\Analytics\Enum\FinalAction;
use Supertab\Connect\Analytics\Enum\TokenOutcome;
use Supertab\Connect\Analytics\HttpAnalyticsTransport;
use Supertab\Connect\Enum\EnforcementMode;
use Supertab\Connect\Exception\HttpException;
use Supertab\Connect\Http\HttpClientInterface;
use Supertab\Connect\Http\RequestContext;

final class HttpAnalyticsTransportTest extends TestCase
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
                enforcementMode: EnforcementMode::OBSERVE,
            ),
        );
    }

    public function test_posts_event_to_ingest_endpoint(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects($this->once())
            ->method('post')
            ->with(
                'https://api-connect.supertab.co/ingest/events',
                $this->callback(function (string $body): bool {
                    $data = json_decode($body, true);

                    return $data['schema_version'] === 1
                        && $data['source_cdn'] === null
                        && $data['user_agent'] === 'TestBot/1.0'
                        && $data['client_ip'] === '::ffff:203.0.113.1'
                        && $data['final_action'] === 'allow'
                        && is_string($data['request_id']) && $data['request_id'] !== '';
                }),
                $this->callback(function (array $headers): bool {
                    return $headers['Authorization'] === 'Bearer test-api-key'
                        && $headers['Content-Type'] === 'application/json';
                }),
            )
            ->willReturn(['statusCode' => 202, 'body' => '']);

        $transport = new HttpAnalyticsTransport(
            apiKey: 'test-api-key',
            baseUrl: 'https://api-connect.supertab.co',
            httpClient: $httpClient,
        );

        $transport->emit($this->event());
    }

    public function test_strips_trailing_slash_from_base_url(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects($this->once())
            ->method('post')
            ->with('https://api-connect.supertab.co/ingest/events', $this->anything(), $this->anything())
            ->willReturn(['statusCode' => 202, 'body' => '']);

        $transport = new HttpAnalyticsTransport(
            apiKey: 'test-api-key',
            baseUrl: 'https://api-connect.supertab.co/',
            httpClient: $httpClient,
        );

        $transport->emit($this->event());
    }

    public function test_swallows_http_error_status(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->method('post')->willReturn(['statusCode' => 500, 'body' => 'boom']);

        $transport = new HttpAnalyticsTransport(
            apiKey: 'test-api-key',
            baseUrl: 'https://api-connect.supertab.co',
            httpClient: $httpClient,
        );

        $transport->emit($this->event());

        $this->assertTrue(true); // reached without throwing
    }

    public function test_swallows_network_exception(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->method('post')->willThrowException(new HttpException('Connection refused', 0));

        $transport = new HttpAnalyticsTransport(
            apiKey: 'test-api-key',
            baseUrl: 'https://api-connect.supertab.co',
            httpClient: $httpClient,
        );

        $transport->emit($this->event());

        $this->assertTrue(true); // reached without throwing
    }
}
