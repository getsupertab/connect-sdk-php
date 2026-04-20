<?php

declare(strict_types=1);

namespace Supertab\Connect\Tests\Http;

use PHPUnit\Framework\TestCase;
use Supertab\Connect\Http\Headers;

final class HeadersTest extends TestCase
{
    public function test_lowercases_keys_and_applies_h_prefix(): void
    {
        $result = Headers::toEventProperties([
            'Accept-Language' => 'en-US',
            'X-Custom' => 'value',
        ]);

        $this->assertSame([
            'h_accept-language' => 'en-US',
            'h_x-custom' => 'value',
        ], $result);
    }

    public function test_drops_user_agent_since_already_captured(): void
    {
        $result = Headers::toEventProperties([
            'User-Agent' => 'GPTBot/1.0',
            'Accept' => 'text/html',
        ]);

        $this->assertSame(['h_accept' => 'text/html'], $result);
    }

    public function test_drops_credential_and_sdk_internal_headers_regardless_of_casing(): void
    {
        $result = Headers::toEventProperties([
            'Authorization' => 'License abc123',
            'COOKIE' => 'session=xyz',
            'Set-Cookie' => 'foo=bar',
            'Proxy-Authorization' => 'Basic xxx',
            'X-API-Key' => 'sk_123',
            'X-Amz-Security-Token' => 'amz-token',
            'X-License-Auth' => 'cf-request-id',
            'Accept' => 'application/json',
        ]);

        $this->assertSame(['h_accept' => 'application/json'], $result);
    }

    public function test_drops_client_ip_headers_to_avoid_pii_leakage(): void
    {
        $result = Headers::toEventProperties([
            'Forwarded' => 'for=203.0.113.1;proto=https',
            'X-Forwarded-For' => '203.0.113.1',
            'X-Real-IP' => '203.0.113.1',
            'CF-Connecting-IP' => '203.0.113.1',
            'True-Client-IP' => '203.0.113.1',
            'Accept' => 'text/html',
        ]);

        $this->assertSame(['h_accept' => 'text/html'], $result);
    }

    public function test_returns_empty_array_for_empty_input(): void
    {
        $this->assertSame([], Headers::toEventProperties([]));
    }

    public function test_preserves_header_values_exactly(): void
    {
        $result = Headers::toEventProperties([
            'X-Custom' => '  value with spaces  ',
        ]);

        $this->assertSame('  value with spaces  ', $result['h_x-custom']);
    }

    public function test_skips_non_string_keys_and_values(): void
    {
        $result = Headers::toEventProperties([
            'Accept' => 'text/html',
            'X-Multi' => ['v1', 'v2'],
            0 => 'numeric-key',
            'X-Valid' => 'ok',
        ]);

        $this->assertSame([
            'h_accept' => 'text/html',
            'h_x-valid' => 'ok',
        ], $result);
    }
}
