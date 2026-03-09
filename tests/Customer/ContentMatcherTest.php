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

    // --- scorePathPattern: prefix matching without wildcards ---

    public function test_score_matches_exact_path(): void
    {
        $this->assertSame(8, ContentMatcher::scorePathPattern('/content', '/content'));
    }

    public function test_score_matches_at_segment_boundary(): void
    {
        $this->assertSame(8, ContentMatcher::scorePathPattern('/content', '/content/article'));
    }

    public function test_score_does_not_match_non_segment_prefix(): void
    {
        $this->assertSame(-1, ContentMatcher::scorePathPattern('/content', '/content-other'));
    }

    public function test_score_root_prefix_matches_any_path(): void
    {
        $this->assertSame(1, ContentMatcher::scorePathPattern('/', '/anything'));
    }

    // --- scorePathPattern: trailing wildcard ---

    public function test_score_trailing_wildcard_matches_sub_path(): void
    {
        $this->assertSame(9, ContentMatcher::scorePathPattern('/content/*', '/content/article'));
    }

    public function test_score_trailing_wildcard_matches_across_segments(): void
    {
        $this->assertSame(9, ContentMatcher::scorePathPattern('/content/*', '/content/a/b'));
    }

    public function test_score_trailing_wildcard_does_not_match_unrelated(): void
    {
        $this->assertSame(-1, ContentMatcher::scorePathPattern('/content/*', '/other'));
    }

    // --- scorePathPattern: mid-path wildcard ---

    public function test_score_mid_wildcard_matches_single_segment(): void
    {
        $this->assertSame(17, ContentMatcher::scorePathPattern('/content/*/article', '/content/news/article'));
    }

    public function test_score_mid_wildcard_matches_multiple_segments(): void
    {
        $this->assertSame(17, ContentMatcher::scorePathPattern('/content/*/article', '/content/a/b/article'));
    }

    public function test_score_mid_wildcard_does_not_match_wrong_suffix(): void
    {
        $this->assertSame(-1, ContentMatcher::scorePathPattern('/content/*/article', '/content/news/other'));
    }

    public function test_score_mid_wildcard_prefix_matches_beyond(): void
    {
        $this->assertSame(17, ContentMatcher::scorePathPattern('/content/*/article', '/content/news/article/comments'));
    }

    // --- scorePathPattern: catch-all wildcard ---

    public function test_score_catch_all_matches_any_path(): void
    {
        $this->assertSame(1, ContentMatcher::scorePathPattern('/*', '/anything'));
    }

    public function test_score_catch_all_matches_nested_path(): void
    {
        $this->assertSame(1, ContentMatcher::scorePathPattern('/*', '/a/b/c'));
    }

    // --- scorePathPattern: anchored patterns with $ ---

    public function test_score_anchored_matches_exact_path(): void
    {
        $this->assertSame(5, ContentMatcher::scorePathPattern('/page$', '/page'));
    }

    public function test_score_anchored_rejects_path_with_suffix(): void
    {
        $this->assertSame(-1, ContentMatcher::scorePathPattern('/page$', '/page/more'));
    }

    public function test_score_anchored_with_mid_wildcard_matches(): void
    {
        $this->assertSame(17, ContentMatcher::scorePathPattern('/content/*/article$', '/content/news/article'));
    }

    public function test_score_anchored_with_mid_wildcard_rejects_suffix(): void
    {
        $this->assertSame(-1, ContentMatcher::scorePathPattern('/content/*/article$', '/content/news/article/extra'));
    }

    // --- scorePathPattern: special characters ---

    public function test_score_dollar_in_middle_is_literal(): void
    {
        $this->assertSame(6, ContentMatcher::scorePathPattern('/pa$ge', '/pa$ge'));
    }

    public function test_score_dot_is_literal_match(): void
    {
        $this->assertSame(10, ContentMatcher::scorePathPattern('/page.html$', '/page.html'));
    }

    public function test_score_dot_does_not_match_other_char(): void
    {
        $this->assertSame(-1, ContentMatcher::scorePathPattern('/page.html$', '/pagexhtml'));
    }

    // --- scorePathPattern: specificity ordering ---

    public function test_score_more_literal_chars_means_higher_specificity(): void
    {
        $path = '/content/news/article';
        $broad = ContentMatcher::scorePathPattern('/*', $path);
        $mid = ContentMatcher::scorePathPattern('/content/*', $path);
        $specific = ContentMatcher::scorePathPattern('/content/*/article', $path);

        $this->assertLessThan($mid, $broad);
        $this->assertLessThan($specific, $mid);
    }

    // --- findBestMatch: integration tests for new pattern features ---

    public function test_segment_boundary_prefix_match(): void
    {
        $blocks = [
            new ContentBlock(
                urlPattern: 'http://example.com/*',
                server: 'http://token.example.com',
                licenseXml: '<license />',
            ),
            new ContentBlock(
                urlPattern: 'http://example.com/content',
                server: 'http://token.example.com',
                licenseXml: '<license />',
            ),
        ];

        $result = ContentMatcher::findBestMatch($blocks, 'http://example.com/content/article');

        $this->assertNotNull($result);
        $this->assertSame('http://example.com/content', $result->urlPattern);
    }

    public function test_non_segment_prefix_falls_back_to_catch_all(): void
    {
        $blocks = [
            new ContentBlock(
                urlPattern: 'http://example.com/*',
                server: 'http://token.example.com',
                licenseXml: '<license />',
            ),
            new ContentBlock(
                urlPattern: 'http://example.com/content',
                server: 'http://token.example.com',
                licenseXml: '<license />',
            ),
        ];

        $result = ContentMatcher::findBestMatch($blocks, 'http://example.com/content-other');

        $this->assertNotNull($result);
        $this->assertSame('http://example.com/*', $result->urlPattern);
    }

    public function test_mid_path_wildcard_wins_over_bare_prefix(): void
    {
        $blocks = [
            new ContentBlock(
                urlPattern: 'http://example.com/*',
                server: 'http://token.example.com',
                licenseXml: '<license />',
            ),
            new ContentBlock(
                urlPattern: 'http://example.com/content',
                server: 'http://token.example.com',
                licenseXml: '<license />',
            ),
            new ContentBlock(
                urlPattern: 'http://example.com/content/*/article',
                server: 'http://token.example.com',
                licenseXml: '<license />',
            ),
        ];

        $result = ContentMatcher::findBestMatch($blocks, 'http://example.com/content/news/article');

        $this->assertNotNull($result);
        $this->assertSame('http://example.com/content/*/article', $result->urlPattern);
    }
}
