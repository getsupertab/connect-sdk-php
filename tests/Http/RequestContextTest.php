<?php

declare(strict_types=1);

namespace Supertab\Connect\Tests\Http;

use PHPUnit\Framework\TestCase;
use Supertab\Connect\Http\RequestContext;

final class RequestContextTest extends TestCase
{
    public function test_constructor_stores_analytics_signal_fields(): void
    {
        $ctx = new RequestContext(
            url: 'https://example.com/article',
            method: 'POST',
            clientIp: '203.0.113.1',
            requestId: 'req-123',
            requestCountry: 'DE',
            requestAsn: 13335,
            tlsFingerprint: 'ja3hash',
        );

        $this->assertSame('POST', $ctx->method);
        $this->assertSame('203.0.113.1', $ctx->clientIp);
        $this->assertSame('req-123', $ctx->requestId);
        $this->assertSame('DE', $ctx->requestCountry);
        $this->assertSame(13335, $ctx->requestAsn);
        $this->assertSame('ja3hash', $ctx->tlsFingerprint);
    }

    public function test_signal_fields_default_to_null(): void
    {
        $ctx = new RequestContext(url: 'https://example.com/article');

        $this->assertNull($ctx->method);
        $this->assertNull($ctx->clientIp);
        $this->assertNull($ctx->requestId);
        $this->assertNull($ctx->requestCountry);
        $this->assertNull($ctx->requestAsn);
        $this->assertNull($ctx->tlsFingerprint);
    }

    public function test_from_globals_populates_method_client_ip_and_request_id(): void
    {
        $original = $_SERVER;
        try {
            $_SERVER = [
                'HTTPS' => 'on',
                'HTTP_HOST' => 'example.com',
                'REQUEST_URI' => '/article',
                'REQUEST_METHOD' => 'GET',
                'REMOTE_ADDR' => '203.0.113.9',
                'HTTP_X_REQUEST_ID' => 'req-from-header',
            ];

            $ctx = RequestContext::fromGlobals();

            $this->assertSame('GET', $ctx->method);
            $this->assertSame('203.0.113.9', $ctx->clientIp);
            $this->assertSame('req-from-header', $ctx->requestId);
        } finally {
            $_SERVER = $original;
        }
    }

    public function test_resolve_authorization_prefers_server_http_authorization(): void
    {
        $auth = RequestContext::resolveAuthorizationHeader(
            ['HTTP_AUTHORIZATION' => 'Bearer from-server'],
            ['Authorization' => 'Bearer from-headers'],
        );

        $this->assertSame('Bearer from-server', $auth);
    }

    public function test_resolve_authorization_falls_back_to_redirect_http_authorization(): void
    {
        $auth = RequestContext::resolveAuthorizationHeader(
            ['REDIRECT_HTTP_AUTHORIZATION' => 'Bearer from-redirect'],
            [],
        );

        $this->assertSame('Bearer from-redirect', $auth);
    }

    public function test_resolve_authorization_falls_back_to_request_headers(): void
    {
        // Apache mod_php withholds Authorization from $_SERVER entirely;
        // the raw request headers (getallheaders()) are the only source.
        $auth = RequestContext::resolveAuthorizationHeader(
            ['HTTP_HOST' => 'example.com'],
            ['Authorization' => 'Bearer from-headers'],
        );

        $this->assertSame('Bearer from-headers', $auth);
    }

    public function test_resolve_authorization_matches_header_name_case_insensitively(): void
    {
        $auth = RequestContext::resolveAuthorizationHeader(
            [],
            ['authorization' => 'License from-lowercase'],
        );

        $this->assertSame('License from-lowercase', $auth);
    }

    public function test_resolve_authorization_returns_null_when_absent_everywhere(): void
    {
        $this->assertNull(RequestContext::resolveAuthorizationHeader(
            ['HTTP_HOST' => 'example.com'],
            ['User-Agent' => 'curl'],
        ));
    }

    public function test_from_globals_leaves_injection_only_signals_null(): void
    {
        $original = $_SERVER;
        try {
            $_SERVER = [
                'HTTP_HOST' => 'example.com',
                'REQUEST_URI' => '/article',
                'REQUEST_METHOD' => 'GET',
                'REMOTE_ADDR' => '203.0.113.9',
            ];

            $ctx = RequestContext::fromGlobals();

            // No reliable origin-side source — these are injection-only.
            $this->assertNull($ctx->requestCountry);
            $this->assertNull($ctx->requestAsn);
            $this->assertNull($ctx->tlsFingerprint);
            // No X-Request-ID header present → null (factory will generate a fallback).
            $this->assertNull($ctx->requestId);
        } finally {
            $_SERVER = $original;
        }
    }
}
