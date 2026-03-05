<?php

declare(strict_types=1);

namespace Supertab\Connect\Tests\Customer;

use PHPUnit\Framework\TestCase;
use Supertab\Connect\Customer\ContentBlock;
use Supertab\Connect\Customer\ContentMatcher;

final class ContentMatcherTest extends TestCase
{
    /** @var list<ContentBlock> */
    private array $blocks;

    protected function setUp(): void
    {
        $this->blocks = [
            new ContentBlock(
                urlPattern: 'http://127.0.0.1:7676/*',
                server: 'http://127.0.0.1:8787',
                licenseXml: '<license><link rel="self" /></license>',
            ),
            new ContentBlock(
                urlPattern: 'http://127.0.0.1:7676/article/*',
                server: 'http://127.0.0.1:8787',
                licenseXml: '<license><link rel="self" /></license>',
            ),
            new ContentBlock(
                urlPattern: 'http://127.0.0.1:7676/article/',
                server: 'http://127.0.0.1:8787',
                licenseXml: '<license><link rel="self" /></license>',
            ),
        ];
    }

    public function test_exact_path_match_wins(): void
    {
        $result = ContentMatcher::findBestMatch($this->blocks, 'http://127.0.0.1:7676/article/');

        $this->assertNotNull($result);
        $this->assertSame('http://127.0.0.1:7676/article/', $result->urlPattern);
    }

    public function test_more_specific_wildcard_wins(): void
    {
        $result = ContentMatcher::findBestMatch($this->blocks, 'http://127.0.0.1:7676/article/some-slug');

        $this->assertNotNull($result);
        $this->assertSame('http://127.0.0.1:7676/article/*', $result->urlPattern);
    }

    public function test_falls_back_to_broader_wildcard(): void
    {
        $result = ContentMatcher::findBestMatch($this->blocks, 'http://127.0.0.1:7676/other-page');

        $this->assertNotNull($result);
        $this->assertSame('http://127.0.0.1:7676/*', $result->urlPattern);
    }

    public function test_no_match_for_different_host(): void
    {
        $result = ContentMatcher::findBestMatch($this->blocks, 'http://other-host.com/article/some-slug');

        $this->assertNull($result);
    }

    public function test_no_match_for_different_port(): void
    {
        $result = ContentMatcher::findBestMatch($this->blocks, 'http://127.0.0.1:9999/article/some-slug');

        $this->assertNull($result);
    }

    public function test_skips_invalid_url_patterns_gracefully(): void
    {
        $blocks = [
            new ContentBlock(
                urlPattern: ':::not-a-url',
                server: 'http://example.com',
                licenseXml: '<license />',
            ),
            new ContentBlock(
                urlPattern: 'http://example.com/*',
                server: 'http://token.example.com',
                licenseXml: '<license />',
            ),
        ];

        $result = ContentMatcher::findBestMatch($blocks, 'http://example.com/page');

        $this->assertNotNull($result);
        $this->assertSame('http://example.com/*', $result->urlPattern);
    }

    public function test_handles_port_in_host_matching(): void
    {
        $blocks = [
            new ContentBlock(
                urlPattern: 'http://localhost:3000/*',
                server: 'http://localhost:4000',
                licenseXml: '<license />',
            ),
        ];

        $result = ContentMatcher::findBestMatch($blocks, 'http://localhost:3000/page');
        $this->assertNotNull($result);

        // Different port should not match
        $result = ContentMatcher::findBestMatch($blocks, 'http://localhost:3001/page');
        $this->assertNull($result);
    }

    public function test_returns_null_for_empty_blocks(): void
    {
        $result = ContentMatcher::findBestMatch([], 'http://example.com/page');

        $this->assertNull($result);
    }
}
