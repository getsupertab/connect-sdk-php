<?php

declare(strict_types=1);

namespace Supertab\Connect\Tests;

use PHPUnit\Framework\TestCase;
use Supertab\Connect\Analytics\AnalyticsEvent;
use Supertab\Connect\Analytics\AnalyticsTransportInterface;
use Supertab\Connect\Analytics\HttpAnalyticsTransport;
use Supertab\Connect\Bot\BotDetectorInterface;
use Supertab\Connect\Enum\EnforcementMode;
use Supertab\Connect\Http\HttpClientInterface;
use Supertab\Connect\Http\RequestContext;
use Supertab\Connect\Result\AllowResult;
use Supertab\Connect\Result\BlockResult;
use Supertab\Connect\SupertabConnect;

final class SupertabConnectAnalyticsTest extends TestCase
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

    /**
     * @param  list<AnalyticsEvent>  $captured
     */
    private function recordingTransport(array &$captured): AnalyticsTransportInterface
    {
        $transport = $this->createMock(AnalyticsTransportInterface::class);
        $transport->method('emit')->willReturnCallback(function (AnalyticsEvent $event) use (&$captured): void {
            $captured[] = $event;
        });

        return $transport;
    }

    private function botDetector(bool $isBot): BotDetectorInterface
    {
        $detector = $this->createMock(BotDetectorInterface::class);
        $detector->method('isBot')->willReturn($isBot);

        return $detector;
    }

    // --- Off by default ---

    public function test_analytics_disabled_by_default_makes_no_http_call(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects($this->never())->method('post');

        $stc = new SupertabConnect(apiKey: 'test-key', httpClient: $httpClient);

        $result = $stc->handleRequest(new RequestContext(url: 'https://example.com/article'));

        $this->assertInstanceOf(AllowResult::class, $result);
    }

    public function test_injected_transport_emits_to_ingest_endpoint(): void
    {
        // The default adaptive transport talks raw sockets. The injection seam is
        // the escape hatch for forcing plain cURL; verify it emits end-to-end.
        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects($this->once())
            ->method('post')
            ->with(
                $this->callback(fn (string $url): bool => str_ends_with($url, '/ingest/events')),
                $this->anything(),
                $this->callback(fn (array $headers): bool => $headers['Authorization'] === 'Bearer test-key'),
            )
            ->willReturn(['statusCode' => 202, 'body' => '']);

        $stc = new SupertabConnect(
            apiKey: 'test-key',
            analyticsEnabled: true,
            analyticsTransport: new HttpAnalyticsTransport('test-key', SupertabConnect::getBaseUrl(), $httpClient),
        );

        $stc->handleRequest(new RequestContext(url: 'https://example.com/article'));
    }

    // --- One event per request, correct decision per branch ---

    public function test_emits_absent_allow_for_non_bot_without_token(): void
    {
        $captured = [];
        $stc = new SupertabConnect(
            apiKey: 'test-key',
            enforcement: EnforcementMode::STRICT,
            analyticsTransport: $this->recordingTransport($captured),
        );

        $result = $stc->handleRequest(new RequestContext(url: 'https://example.com/article'));

        $this->assertInstanceOf(AllowResult::class, $result);
        $this->assertCount(1, $captured);
        $payload = $captured[0]->toArray();
        $this->assertFalse($payload['has_token']);
        $this->assertSame('absent', $payload['token_outcome']);
        $this->assertSame('allow', $payload['final_action']);
    }

    public function test_emits_observe_for_soft_mode_bot(): void
    {
        $captured = [];
        $stc = new SupertabConnect(
            apiKey: 'test-key',
            enforcement: EnforcementMode::SOFT,
            botDetector: $this->botDetector(true),
            analyticsTransport: $this->recordingTransport($captured),
        );

        $result = $stc->handleRequest(new RequestContext(url: 'https://example.com/article'));

        $this->assertInstanceOf(AllowResult::class, $result);
        $this->assertSame('token_required', $result->headers['X-RSL-Status']);
        $this->assertCount(1, $captured);
        $payload = $captured[0]->toArray();
        $this->assertSame('absent', $payload['token_outcome']);
        $this->assertSame('observe', $payload['final_action']);
        $this->assertSame('observe', $payload['enforcement_mode']);
    }

    public function test_emits_block_for_strict_mode_bot(): void
    {
        $captured = [];
        $stc = new SupertabConnect(
            apiKey: 'test-key',
            enforcement: EnforcementMode::STRICT,
            botDetector: $this->botDetector(true),
            analyticsTransport: $this->recordingTransport($captured),
        );

        $result = $stc->handleRequest(new RequestContext(url: 'https://example.com/article'));

        $this->assertInstanceOf(BlockResult::class, $result);
        $this->assertCount(1, $captured);
        $payload = $captured[0]->toArray();
        $this->assertSame('absent', $payload['token_outcome']);
        $this->assertSame('block', $payload['final_action']);
        $this->assertSame('enforce', $payload['enforcement_mode']);
    }

    public function test_emits_not_validated_for_disabled_mode_with_token(): void
    {
        $captured = [];
        $stc = new SupertabConnect(
            apiKey: 'test-key',
            enforcement: EnforcementMode::DISABLED,
            analyticsTransport: $this->recordingTransport($captured),
        );

        $result = $stc->handleRequest(new RequestContext(
            url: 'https://example.com/article',
            authorizationHeader: 'License some-token',
        ));

        $this->assertInstanceOf(AllowResult::class, $result);
        $this->assertCount(1, $captured);
        $payload = $captured[0]->toArray();
        $this->assertTrue($payload['has_token']);
        $this->assertSame('not_validated', $payload['token_outcome']);
        $this->assertSame('allow', $payload['final_action']);
    }

    public function test_emits_block_with_malformed_for_invalid_token(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->method('post')->willReturn(['statusCode' => 200, 'body' => '']); // absorb billing POST

        $captured = [];
        $stc = new SupertabConnect(
            apiKey: 'test-key',
            enforcement: EnforcementMode::SOFT,
            httpClient: $httpClient,
            analyticsTransport: $this->recordingTransport($captured),
        );

        $result = $stc->handleRequest(new RequestContext(
            url: 'https://example.com/article',
            authorizationHeader: 'License not-a-real-jwt',
        ));

        $this->assertInstanceOf(BlockResult::class, $result);
        $this->assertCount(1, $captured);
        $payload = $captured[0]->toArray();
        $this->assertTrue($payload['has_token']);
        $this->assertSame('malformed', $payload['token_outcome']);
        $this->assertSame('block', $payload['final_action']);
    }

    // --- Fail-open ---

    public function test_handle_request_is_fail_open_when_transport_throws(): void
    {
        $transport = $this->createMock(AnalyticsTransportInterface::class);
        $transport->method('emit')->willThrowException(new \RuntimeException('boom'));

        $stc = new SupertabConnect(
            apiKey: 'test-key',
            enforcement: EnforcementMode::STRICT,
            botDetector: $this->botDetector(true),
            analyticsTransport: $transport,
        );

        // Emission throwing must not break request handling.
        $result = $stc->handleRequest(new RequestContext(url: 'https://example.com/article'));

        $this->assertInstanceOf(BlockResult::class, $result);
    }
}
