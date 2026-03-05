<?php

declare(strict_types=1);

namespace Supertab\Connect\Tests\License;

use PHPUnit\Framework\TestCase;
use Supertab\Connect\Enum\HandlerAction;
use Supertab\Connect\Enum\LicenseTokenInvalidReason;
use Supertab\Connect\License\ResponseBuilder;
use Supertab\Connect\Result\AllowResult;
use Supertab\Connect\Result\BlockResult;

final class ResponseBuilderTest extends TestCase
{
    public function test_build_signal_result(): void
    {
        $result = ResponseBuilder::buildSignalResult('https://example.com/article');

        $this->assertInstanceOf(AllowResult::class, $result);
        $this->assertSame(HandlerAction::ALLOW, $result->action);
        $this->assertSame('token_required', $result->headers['X-RSL-Status']);
        $this->assertSame('missing', $result->headers['X-RSL-Reason']);
        $this->assertStringContainsString('https://example.com/license.xml', $result->headers['Link']);
        $this->assertStringContainsString('rel="license"', $result->headers['Link']);
    }

    public function test_build_block_result_missing_token(): void
    {
        $result = ResponseBuilder::buildBlockResult(
            LicenseTokenInvalidReason::MISSING_TOKEN,
            'Authorization header missing or malformed',
            'https://example.com/article',
        );

        $this->assertInstanceOf(BlockResult::class, $result);
        $this->assertSame(HandlerAction::BLOCK, $result->action);
        $this->assertSame(401, $result->status);
        $this->assertStringContainsString('invalid_request', $result->headers['WWW-Authenticate']);
        $this->assertStringContainsString('https://example.com/license.xml', $result->headers['Link']);
        $this->assertSame('text/plain; charset=UTF-8', $result->headers['Content-Type']);
    }

    public function test_build_block_result_invalid_audience(): void
    {
        $result = ResponseBuilder::buildBlockResult(
            LicenseTokenInvalidReason::INVALID_AUDIENCE,
            'The license does not grant access to this resource',
            'https://example.com/article',
        );

        $this->assertSame(403, $result->status);
        $this->assertStringContainsString('insufficient_scope', $result->headers['WWW-Authenticate']);
    }

    public function test_build_block_result_server_error(): void
    {
        $result = ResponseBuilder::buildBlockResult(
            LicenseTokenInvalidReason::SERVER_ERROR,
            'The server encountered an error validating the license',
            'https://example.com/article',
        );

        $this->assertSame(503, $result->status);
        $this->assertStringContainsString('server_error', $result->headers['WWW-Authenticate']);
    }

    public function test_build_block_result_expired(): void
    {
        $result = ResponseBuilder::buildBlockResult(
            LicenseTokenInvalidReason::EXPIRED,
            'The license token has expired',
            'https://example.com/article',
        );

        $this->assertSame(401, $result->status);
        $this->assertStringContainsString('invalid_token', $result->headers['WWW-Authenticate']);
    }

    public function test_generate_license_link(): void
    {
        $this->assertSame(
            'https://example.com/license.xml',
            ResponseBuilder::generateLicenseLink('https://example.com/some/path'),
        );
    }

    public function test_generate_license_link_with_port(): void
    {
        $this->assertSame(
            'https://example.com:8080/license.xml',
            ResponseBuilder::generateLicenseLink('https://example.com:8080/some/path'),
        );
    }

    public function test_generate_license_link_invalid_url(): void
    {
        $this->assertSame(
            '/license.xml',
            ResponseBuilder::generateLicenseLink('not-a-valid-url'),
        );
    }

    public function test_sanitizes_header_values(): void
    {
        $result = ResponseBuilder::buildBlockResult(
            LicenseTokenInvalidReason::EXPIRED,
            "Some\r\nmalicious\r\nheader\r\n: injection",
            'https://example.com/article',
        );

        $this->assertStringNotContainsString("\r", $result->headers['WWW-Authenticate']);
        $this->assertStringNotContainsString("\n", $result->headers['WWW-Authenticate']);
    }
}
