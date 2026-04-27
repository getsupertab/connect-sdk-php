<?php

declare(strict_types=1);

namespace Supertab\Connect\Tests;

use PHPUnit\Framework\TestCase;
use Supertab\Connect\Bot\BotDetectorInterface;
use Supertab\Connect\Enum\EnforcementMode;
use Supertab\Connect\Enum\HandlerAction;
use Supertab\Connect\Http\HttpClientInterface;
use Supertab\Connect\Http\RequestContext;
use Supertab\Connect\Result\AllowResult;
use Supertab\Connect\Result\BlockResult;
use Supertab\Connect\SupertabConnect;

final class SupertabConnectTest extends TestCase
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

    // --- Constructor / Singleton ---

    public function test_constructor_requires_api_key(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SupertabConnect(apiKey: '');
    }

    public function test_singleton_prevents_conflicting_instances(): void
    {
        new SupertabConnect(apiKey: 'key-1');

        $this->expectException(\LogicException::class);
        new SupertabConnect(apiKey: 'key-2');
    }

    public function test_reset_instance_allows_new_config(): void
    {
        new SupertabConnect(apiKey: 'key-1');
        SupertabConnect::resetInstance();

        // Should not throw
        $instance = new SupertabConnect(apiKey: 'key-2');
        $this->assertInstanceOf(SupertabConnect::class, $instance);
    }

    // --- Base URL ---

    public function test_set_and_get_base_url(): void
    {
        SupertabConnect::setBaseUrl('https://custom.example.com/');
        $this->assertSame('https://custom.example.com', SupertabConnect::getBaseUrl());
    }

    public function test_constructor_base_url_sets_base_url(): void
    {
        new SupertabConnect(
            apiKey: 'test-key',
            baseUrl: 'https://custom.example.com/',
        );

        $this->assertSame('https://custom.example.com', SupertabConnect::getBaseUrl());
    }

    // --- handleRequest: Enforcement Modes ---

    public function test_handle_request_disabled_mode_allows_all(): void
    {
        $stc = new SupertabConnect(
            apiKey: 'test-key',
            enforcement: EnforcementMode::DISABLED,
        );

        $context = new RequestContext(
            url: 'https://example.com/article',
            authorizationHeader: null,
        );

        $result = $stc->handleRequest($context);

        $this->assertInstanceOf(AllowResult::class, $result);
        $this->assertSame(HandlerAction::ALLOW, $result->action);
    }

    public function test_handle_request_soft_mode_signals_when_bot_and_no_token(): void
    {
        $botDetector = $this->createMock(BotDetectorInterface::class);
        $botDetector->method('isBot')->willReturn(true);

        $stc = new SupertabConnect(
            apiKey: 'test-key',
            enforcement: EnforcementMode::SOFT,
            botDetector: $botDetector,
        );

        $context = new RequestContext(
            url: 'https://example.com/article',
            authorizationHeader: null,
        );

        $result = $stc->handleRequest($context);

        $this->assertInstanceOf(AllowResult::class, $result);
        $this->assertSame('token_required', $result->headers['X-RSL-Status']);
        $this->assertSame('missing', $result->headers['X-RSL-Reason']);
    }

    public function test_handle_request_strict_mode_blocks_when_bot_and_no_token(): void
    {
        $botDetector = $this->createMock(BotDetectorInterface::class);
        $botDetector->method('isBot')->willReturn(true);

        $stc = new SupertabConnect(
            apiKey: 'test-key',
            enforcement: EnforcementMode::STRICT,
            botDetector: $botDetector,
        );

        $context = new RequestContext(
            url: 'https://example.com/article',
            authorizationHeader: null,
        );

        $result = $stc->handleRequest($context);

        $this->assertInstanceOf(BlockResult::class, $result);
        $this->assertSame(HandlerAction::BLOCK, $result->action);
        $this->assertSame(401, $result->status);
    }

    public function test_handle_request_disabled_mode_allows_with_token(): void
    {
        $stc = new SupertabConnect(
            apiKey: 'test-key',
            enforcement: EnforcementMode::DISABLED,
        );

        $context = new RequestContext(
            url: 'https://example.com/article',
            authorizationHeader: 'License some-invalid-token',
        );

        // DISABLED mode allows even with a token present
        $result = $stc->handleRequest($context);

        $this->assertInstanceOf(AllowResult::class, $result);
    }

    public function test_handle_request_ignores_non_license_auth_header_with_bot(): void
    {
        $botDetector = $this->createMock(BotDetectorInterface::class);
        $botDetector->method('isBot')->willReturn(true);

        $stc = new SupertabConnect(
            apiKey: 'test-key',
            enforcement: EnforcementMode::SOFT,
            botDetector: $botDetector,
        );

        $context = new RequestContext(
            url: 'https://example.com/article',
            authorizationHeader: 'Bearer some-bearer-token',
        );

        // Non-"License " prefix is treated as no token → bot detection applies
        $result = $stc->handleRequest($context);

        $this->assertInstanceOf(AllowResult::class, $result);
        $this->assertArrayHasKey('X-RSL-Status', $result->headers);
    }

    // --- Bot Detection Integration ---

    public function test_handle_request_allows_non_bot_without_token(): void
    {
        $botDetector = $this->createMock(BotDetectorInterface::class);
        $botDetector->method('isBot')->willReturn(false);

        $stc = new SupertabConnect(
            apiKey: 'test-key',
            enforcement: EnforcementMode::STRICT,
            botDetector: $botDetector,
        );

        $context = new RequestContext(
            url: 'https://example.com/article',
            authorizationHeader: null,
        );

        // Not a bot → always ALLOW, even in STRICT mode
        $result = $stc->handleRequest($context);

        $this->assertInstanceOf(AllowResult::class, $result);
        $this->assertSame(HandlerAction::ALLOW, $result->action);
    }

    public function test_handle_request_bot_disabled_mode_allows(): void
    {
        $botDetector = $this->createMock(BotDetectorInterface::class);
        $botDetector->method('isBot')->willReturn(true);

        $stc = new SupertabConnect(
            apiKey: 'test-key',
            enforcement: EnforcementMode::DISABLED,
            botDetector: $botDetector,
        );

        $context = new RequestContext(
            url: 'https://example.com/article',
            authorizationHeader: null,
        );

        // Bot + DISABLED → still ALLOW
        $result = $stc->handleRequest($context);

        $this->assertInstanceOf(AllowResult::class, $result);
    }

    // --- RequestContext ---

    public function test_request_context_constructor(): void
    {
        $ctx = new RequestContext(
            url: 'https://example.com/path',
            authorizationHeader: 'License abc123',
            userAgent: 'TestBot/1.0',
        );

        $this->assertSame('https://example.com/path', $ctx->url);
        $this->assertSame('License abc123', $ctx->authorizationHeader);
        $this->assertSame('TestBot/1.0', $ctx->userAgent);
    }

    public function test_request_context_constructor_with_new_headers(): void
    {
        $ctx = new RequestContext(
            url: 'https://example.com/path',
            accept: 'text/html',
            acceptLanguage: 'en-US',
            secChUa: '"Chromium";v="120"',
        );

        $this->assertSame('text/html', $ctx->accept);
        $this->assertSame('en-US', $ctx->acceptLanguage);
        $this->assertSame('"Chromium";v="120"', $ctx->secChUa);
    }

    public function test_request_context_headers_defaults_to_empty_array(): void
    {
        $ctx = new RequestContext(url: 'https://example.com/path');

        $this->assertSame([], $ctx->headers);
    }

    public function test_request_context_headers_field_is_stored(): void
    {
        $ctx = new RequestContext(
            url: 'https://example.com/path',
            headers: [
                'accept-language' => 'en-US',
                'x-custom' => 'value',
            ],
        );

        $this->assertSame([
            'accept-language' => 'en-US',
            'x-custom' => 'value',
        ], $ctx->headers);
    }

    // --- Headers forwarding into event properties ---

    public function test_handle_request_forwards_headers_into_event_properties(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $capturedBody = null;
        $httpClient->expects($this->atLeastOnce())
            ->method('post')
            ->willReturnCallback(function (string $url, string $body, array $headers) use (&$capturedBody) {
                if (str_ends_with($url, '/events')) {
                    $capturedBody = $body;
                }

                return ['statusCode' => 200, 'body' => ''];
            });

        $stc = new SupertabConnect(
            apiKey: 'test-key',
            enforcement: EnforcementMode::SOFT,
            httpClient: $httpClient,
        );

        $context = new RequestContext(
            url: 'https://example.com/article',
            authorizationHeader: 'License not-a-real-jwt',
            userAgent: 'TestBot/1.0',
            headers: [
                'accept-language' => 'en-US',
                'x-custom' => 'value',
                'authorization' => 'License not-a-real-jwt',
                'user-agent' => 'TestBot/1.0',
                'x-forwarded-for' => '203.0.113.1',
            ],
        );

        $stc->handleRequest($context);

        $this->assertNotNull($capturedBody);
        $payload = json_decode($capturedBody, true);
        $properties = $payload['properties'];

        $this->assertSame('en-US', $properties['h_accept-language']);
        $this->assertSame('value', $properties['h_x-custom']);
        $this->assertSame('203.0.113.1', $properties['h_x-forwarded-for']);
        $this->assertArrayNotHasKey('h_authorization', $properties);
        $this->assertArrayNotHasKey('h_user-agent', $properties);

        // Standard properties still present
        $this->assertSame('https://example.com/article', $properties['page_url']);
        $this->assertSame('TestBot/1.0', $properties['user_agent']);
    }

    public function test_verify_and_record_accepts_request_headers_argument(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $capturedBody = null;
        $httpClient->expects($this->atLeastOnce())
            ->method('post')
            ->willReturnCallback(function (string $url, string $body, array $headers) use (&$capturedBody) {
                if (str_ends_with($url, '/events')) {
                    $capturedBody = $body;
                }

                return ['statusCode' => 200, 'body' => ''];
            });

        $stc = new SupertabConnect(
            apiKey: 'test-key',
            httpClient: $httpClient,
        );

        $stc->verifyAndRecord(
            token: 'not-a-real-jwt',
            resourceUrl: 'https://example.com/article',
            userAgent: 'TestBot/1.0',
            requestHeaders: [
                'Accept-Language' => 'en-US',
                'Authorization' => 'License xyz',
                'X-Custom' => 'value',
            ],
        );

        $this->assertNotNull($capturedBody);
        $properties = json_decode($capturedBody, true)['properties'];

        $this->assertSame('en-US', $properties['h_accept-language']);
        $this->assertSame('value', $properties['h_x-custom']);
        $this->assertArrayNotHasKey('h_authorization', $properties);
    }

    public function test_request_context_from_globals_collects_all_http_headers(): void
    {
        $original = $_SERVER;
        try {
            $_SERVER = [
                'HTTPS' => 'on',
                'HTTP_HOST' => 'example.com',
                'REQUEST_URI' => '/article',
                'HTTP_ACCEPT_LANGUAGE' => 'en-US,en;q=0.9',
                'HTTP_X_FORWARDED_FOR' => '203.0.113.1',
                'HTTP_AUTHORIZATION' => 'License abc123',
                'HTTP_SEC_CH_UA' => '"Chromium";v="120"',
                'HTTP_X_CUSTOM' => 'hello',
                'CONTENT_TYPE' => 'application/json',
                'CONTENT_LENGTH' => '42',
            ];

            $ctx = RequestContext::fromGlobals();

            $this->assertSame('en-US,en;q=0.9', $ctx->headers['accept-language']);
            $this->assertSame('203.0.113.1', $ctx->headers['x-forwarded-for']);
            $this->assertSame('License abc123', $ctx->headers['authorization']);
            $this->assertSame('"Chromium";v="120"', $ctx->headers['sec-ch-ua']);
            $this->assertSame('hello', $ctx->headers['x-custom']);
            $this->assertSame('application/json', $ctx->headers['content-type']);
            $this->assertSame('42', $ctx->headers['content-length']);
        } finally {
            $_SERVER = $original;
        }
    }
}
