<?php

declare(strict_types=1);

namespace Supertab\Connect\Tests\Http;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;
use Supertab\Connect\Http\HttpClient;

final class HttpClientTest extends TestCase
{
    protected function setUp(): void
    {
        $this->resetUserAgentCache();
    }

    protected function tearDown(): void
    {
        $this->resetUserAgentCache();
    }

    public function test_resolve_user_agent_has_expected_prefix_and_version_segment(): void
    {
        $ua = $this->invokeResolveUserAgent();

        $this->assertMatchesRegularExpression('#^supertab-connect-sdk-php/.+$#', $ua);
    }

    public function test_resolve_user_agent_populates_memoization_cache(): void
    {
        $this->assertNull($this->userAgentProperty()->getValue());

        $ua = $this->invokeResolveUserAgent();

        $this->assertSame($ua, $this->userAgentProperty()->getValue());
    }

    public function test_resolve_user_agent_returns_cached_value_on_subsequent_calls(): void
    {
        $sentinel = 'supertab-connect-sdk-php/sentinel';
        $this->userAgentProperty()->setValue(null, $sentinel);

        $this->assertSame($sentinel, $this->invokeResolveUserAgent());
    }

    private function invokeResolveUserAgent(): string
    {
        $method = (new ReflectionClass(HttpClient::class))->getMethod('resolveUserAgent');

        return $method->invoke(null);
    }

    private function userAgentProperty(): ReflectionProperty
    {
        return (new ReflectionClass(HttpClient::class))->getProperty('userAgent');
    }

    private function resetUserAgentCache(): void
    {
        $this->userAgentProperty()->setValue(null, null);
    }
}
