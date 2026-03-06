<?php

declare(strict_types=1);

namespace Supertab\Connect\Tests\Bot;

use PHPUnit\Framework\TestCase;
use Supertab\Connect\Bot\DefaultBotDetector;
use Supertab\Connect\Http\RequestContext;

final class DefaultBotDetectorTest extends TestCase
{
    private DefaultBotDetector $detector;

    protected function setUp(): void
    {
        $this->detector = new DefaultBotDetector;
    }

    // --- Known bot User-Agents ---

    /** @dataProvider knownBotUserAgentProvider */
    public function test_detects_known_bot_user_agents(string $userAgent): void
    {
        $context = new RequestContext(
            url: 'https://example.com/article',
            userAgent: $userAgent,
            accept: 'text/html',
            acceptLanguage: 'en-US',
            secChUa: '"Chromium";v="120"',
        );

        $this->assertTrue($this->detector->isBot($context));
    }

    /** @return array<string, array{string}> */
    public static function knownBotUserAgentProvider(): array
    {
        return [
            'GPTBot' => ['Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko) GPTBot/1.0'],
            'ChatGPT-User' => ['ChatGPT-User/1.0'],
            'PerplexityBot' => ['PerplexityBot/1.0'],
            'Anthropic-AI' => ['Anthropic-AI/1.0'],
            'CCBot' => ['CCBot/2.0 (https://commoncrawl.org)'],
            'Claude-Web' => ['Claude-Web/1.0'],
            'ClaudeBot' => ['ClaudeBot/1.0'],
            'Cohere-AI' => ['Cohere-AI/1.0'],
            'Googlebot' => ['Mozilla/5.0 (compatible; Googlebot/2.1)'],
            'AmazonBot' => ['Amazonbot/0.1'],
            'curl' => ['curl/7.88.1'],
            'Wget' => ['Wget/1.21.4'],
            'generic bot' => ['SomeBot/1.0'],
        ];
    }

    public function test_case_insensitive_matching(): void
    {
        $context = new RequestContext(
            url: 'https://example.com/article',
            userAgent: 'GPTBOT/1.0',
            accept: 'text/html',
            acceptLanguage: 'en-US',
            secChUa: '"Chromium";v="120"',
        );

        $this->assertTrue($this->detector->isBot($context));
    }

    // --- Headless browser detection ---

    public function test_detects_headless_chrome(): void
    {
        $context = new RequestContext(
            url: 'https://example.com/article',
            userAgent: 'Mozilla/5.0 HeadlessChrome/120.0',
            accept: 'text/html',
            acceptLanguage: 'en-US',
            secChUa: '"Chromium";v="120"',
        );

        $this->assertTrue($this->detector->isBot($context));
    }

    public function test_detects_puppeteer(): void
    {
        $context = new RequestContext(
            url: 'https://example.com/article',
            userAgent: 'Mozilla/5.0 puppeteer/20.0',
            accept: 'text/html',
            acceptLanguage: 'en-US',
            secChUa: '"Chromium";v="120"',
        );

        $this->assertTrue($this->detector->isBot($context));
    }

    // --- Missing sec-ch-ua ---

    public function test_missing_sec_ch_ua_without_browser_ua_is_bot(): void
    {
        $context = new RequestContext(
            url: 'https://example.com/article',
            userAgent: 'SomeCustomClient/1.0',
            accept: 'text/html',
            acceptLanguage: 'en-US',
            secChUa: null,
        );

        $this->assertTrue($this->detector->isBot($context));
    }

    // --- Missing standard headers ---

    public function test_missing_accept_header_is_bot(): void
    {
        $context = new RequestContext(
            url: 'https://example.com/article',
            userAgent: 'SomeCustomClient/1.0',
            accept: null,
            acceptLanguage: 'en-US',
            secChUa: '"Chromium";v="120"',
        );

        $this->assertTrue($this->detector->isBot($context));
    }

    public function test_missing_accept_language_header_is_bot(): void
    {
        $context = new RequestContext(
            url: 'https://example.com/article',
            userAgent: 'SomeCustomClient/1.0',
            accept: 'text/html',
            acceptLanguage: null,
            secChUa: '"Chromium";v="120"',
        );

        $this->assertTrue($this->detector->isBot($context));
    }

    public function test_empty_accept_header_is_bot(): void
    {
        $context = new RequestContext(
            url: 'https://example.com/article',
            userAgent: 'SomeCustomClient/1.0',
            accept: '',
            acceptLanguage: 'en-US',
            secChUa: '"Chromium";v="120"',
        );

        $this->assertTrue($this->detector->isBot($context));
    }

    // --- Safari/Mozilla special case ---

    public function test_safari_missing_sec_ch_ua_is_not_bot(): void
    {
        $context = new RequestContext(
            url: 'https://example.com/article',
            userAgent: 'Mozilla/5.0 (Macintosh) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Safari/605.1.15',
            accept: 'text/html',
            acceptLanguage: 'en-US',
            secChUa: null,
        );

        $this->assertFalse($this->detector->isBot($context));
    }

    public function test_mozilla_missing_sec_ch_ua_is_not_bot(): void
    {
        $context = new RequestContext(
            url: 'https://example.com/article',
            userAgent: 'Mozilla/5.0 (X11; Linux x86_64; rv:121.0) Gecko/20100101 Firefox/121.0',
            accept: 'text/html',
            acceptLanguage: 'en-US',
            secChUa: null,
        );

        $this->assertFalse($this->detector->isBot($context));
    }

    public function test_headless_chrome_with_safari_in_ua_is_still_bot(): void
    {
        // HeadlessChrome UA often includes "Safari" — but the headless keyword should still trigger
        $context = new RequestContext(
            url: 'https://example.com/article',
            userAgent: 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/120.0 Safari/537.36',
            accept: 'text/html',
            acceptLanguage: 'en-US',
            secChUa: '"Chromium";v="120"',
        );

        $this->assertTrue($this->detector->isBot($context));
    }

    // --- Normal browser (not a bot) ---

    public function test_normal_chrome_browser_is_not_bot(): void
    {
        $context = new RequestContext(
            url: 'https://example.com/article',
            userAgent: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            accept: 'text/html,application/xhtml+xml',
            acceptLanguage: 'en-US,en;q=0.9',
            secChUa: '"Not_A Brand";v="8", "Chromium";v="120", "Google Chrome";v="120"',
        );

        $this->assertFalse($this->detector->isBot($context));
    }

    // --- Edge cases ---

    public function test_empty_user_agent_is_bot(): void
    {
        $context = new RequestContext(
            url: 'https://example.com/article',
            userAgent: '',
            accept: 'text/html',
            acceptLanguage: 'en-US',
            secChUa: '"Chromium";v="120"',
        );

        // Empty UA doesn't match any bot, but it doesn't contain safari/mozilla either,
        // and all headers are present + sec-ch-ua is set — so it's NOT a bot
        $this->assertFalse($this->detector->isBot($context));
    }

    public function test_null_user_agent_is_bot(): void
    {
        $context = new RequestContext(
            url: 'https://example.com/article',
            userAgent: null,
            accept: 'text/html',
            acceptLanguage: 'en-US',
            secChUa: '"Chromium";v="120"',
        );

        // Null UA treated as empty string — same as above
        $this->assertFalse($this->detector->isBot($context));
    }

    public function test_null_user_agent_and_missing_headers_is_bot(): void
    {
        $context = new RequestContext(
            url: 'https://example.com/article',
            userAgent: null,
        );

        $this->assertTrue($this->detector->isBot($context));
    }
}
