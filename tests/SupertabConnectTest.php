<?php

declare(strict_types=1);

namespace Supertab\Connect\Tests;

use PHPUnit\Framework\TestCase;
use Supertab\Connect\Bot\BotDetectorInterface;
use Supertab\Connect\Enum\EnforcementMode;
use Supertab\Connect\Enum\HandlerAction;
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

    public function test_handle_request_no_bot_detector_always_allows_without_token(): void
    {
        $stc = new SupertabConnect(
            apiKey: 'test-key',
            enforcement: EnforcementMode::STRICT,
        );

        $context = new RequestContext(
            url: 'https://example.com/article',
            authorizationHeader: null,
        );

        // No bot detector → isBot defaults to false → always ALLOW
        $result = $stc->handleRequest($context);

        $this->assertInstanceOf(AllowResult::class, $result);
        $this->assertSame(HandlerAction::ALLOW, $result->action);
    }

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
}
