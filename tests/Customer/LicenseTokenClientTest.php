<?php

declare(strict_types=1);

namespace Supertab\Connect\Tests\Customer;

use PHPUnit\Framework\TestCase;
use Supertab\Connect\Customer\LicenseTokenClient;
use Supertab\Connect\Exception\SupertabConnectException;
use Supertab\Connect\Http\HttpClientInterface;

final class LicenseTokenClientTest extends TestCase
{
    private const CLIENT_ID = 'test-client';

    private const CLIENT_SECRET = 'test-secret';

    private const RESOURCE_URL = 'http://127.0.0.1:7676/article/my-article';

    private const LICENSE_XML = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rsl xmlns="https://rslstandard.org/rsl">
  <content url="http://127.0.0.1:7676/*" server="http://127.0.0.1:8787">
    <license type="application/vnd.readium.license.status.v1.0+json">
      <link rel="self" href="http://127.0.0.1:8787/license" />
    </license>
  </content>
</rsl>
XML;

    private const LICENSE_XML_PATH_ONLY = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rsl xmlns="https://rslstandard.org/rsl">
  <content url="/*" server="http://127.0.0.1:8787">
    <license type="application/vnd.readium.license.status.v1.0+json">
      <link rel="self" href="http://127.0.0.1:8787/license" />
    </license>
  </content>
</rsl>
XML;

    private const NON_MATCHING_XML = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rsl>
  <content url="http://other-host.com/*" server="http://token.other.com">
    <license type="test"><link rel="self" /></license>
  </content>
</rsl>
XML;

    private const SERVERLESS_XML = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rsl>
  <content url="http://127.0.0.1:7676/*">
    <license type="test"><link rel="self" /></license>
  </content>
</rsl>
XML;

    public function test_obtains_token_successfully(): void
    {
        $fakeToken = $this->createFakeJwt(['exp' => time() + 3600]);

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->method('get')
            ->willReturn(['statusCode' => 200, 'body' => self::LICENSE_XML]);
        $httpClient->method('post')
            ->willReturn(['statusCode' => 200, 'body' => json_encode(['access_token' => $fakeToken])]);

        $client = new LicenseTokenClient($httpClient);
        $token = $client->obtainLicenseToken(self::CLIENT_ID, self::CLIENT_SECRET, self::RESOURCE_URL);

        $this->assertSame($fakeToken, $token);
    }

    public function test_returns_cached_token_on_second_call(): void
    {
        $fakeToken = $this->createFakeJwt(['exp' => time() + 3600]);

        $httpClient = $this->createMock(HttpClientInterface::class);
        // license.xml is fetched per call (the cache key needs the resolved
        // lane), but the token itself is minted exactly once
        $httpClient->expects($this->exactly(2))
            ->method('get')
            ->willReturn(['statusCode' => 200, 'body' => self::LICENSE_XML]);
        $httpClient->expects($this->once())
            ->method('post')
            ->willReturn(['statusCode' => 200, 'body' => json_encode(['access_token' => $fakeToken])]);

        $client = new LicenseTokenClient($httpClient);

        $token1 = $client->obtainLicenseToken(self::CLIENT_ID, self::CLIENT_SECRET, self::RESOURCE_URL);
        $token2 = $client->obtainLicenseToken(self::CLIENT_ID, self::CLIENT_SECRET, self::RESOURCE_URL);

        $this->assertSame($fakeToken, $token1);
        $this->assertSame($fakeToken, $token2);
    }

    public function test_throws_on_license_xml_fetch_failure(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->method('get')
            ->willReturn(['statusCode' => 404, 'body' => 'Not Found']);

        $client = new LicenseTokenClient($httpClient);

        $this->expectException(SupertabConnectException::class);
        $this->expectExceptionMessage('Failed to fetch license.xml');

        $client->obtainLicenseToken(self::CLIENT_ID, self::CLIENT_SECRET, self::RESOURCE_URL);
    }

    public function test_throws_when_no_content_elements(): void
    {
        $emptyXml = '<?xml version="1.0"?><rsl></rsl>';

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->method('get')
            ->willReturn(['statusCode' => 200, 'body' => $emptyXml]);

        $client = new LicenseTokenClient($httpClient);

        $this->expectException(SupertabConnectException::class);
        $this->expectExceptionMessage('No valid <content> elements');

        $client->obtainLicenseToken(self::CLIENT_ID, self::CLIENT_SECRET, self::RESOURCE_URL);
    }

    public function test_mints_license_less_when_no_content_matches(): void
    {
        $fakeToken = $this->createFakeJwt(['exp' => time() + 3600]);
        $posts = [];

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->method('get')
            ->willReturn(['statusCode' => 200, 'body' => self::NON_MATCHING_XML]);
        $httpClient->method('post')
            ->willReturnCallback(function (string $url, string $body) use (&$posts, $fakeToken) {
                $posts[] = ['url' => $url, 'body' => $body];

                return ['statusCode' => 200, 'body' => json_encode(['access_token' => $fakeToken])];
            });

        $client = new LicenseTokenClient($httpClient);
        $token = $client->obtainLicenseToken(self::CLIENT_ID, self::CLIENT_SECRET, self::RESOURCE_URL);

        $this->assertSame($fakeToken, $token);
        $this->assertCount(1, $posts);
        $this->assertSame('https://api-connect.supertab.co/token', $posts[0]['url']);

        parse_str($posts[0]['body'], $params);
        $this->assertSame('client_credentials', $params['grant_type']);
        $this->assertSame(self::RESOURCE_URL, $params['resource']);
        $this->assertArrayNotHasKey('license', $params);
    }

    public function test_agreement_lane_uses_configured_base_url(): void
    {
        $fakeToken = $this->createFakeJwt(['exp' => time() + 3600]);
        $posts = [];

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->method('get')
            ->willReturn(['statusCode' => 200, 'body' => self::NON_MATCHING_XML]);
        $httpClient->method('post')
            ->willReturnCallback(function (string $url, string $body) use (&$posts, $fakeToken) {
                $posts[] = ['url' => $url, 'body' => $body];

                return ['statusCode' => 200, 'body' => json_encode(['access_token' => $fakeToken])];
            });

        $client = new LicenseTokenClient($httpClient, supertabBaseUrl: 'http://api-connect.test/');
        $client->obtainLicenseToken(self::CLIENT_ID, self::CLIENT_SECRET, self::RESOURCE_URL);

        $this->assertCount(1, $posts);
        $this->assertSame('http://api-connect.test/token', $posts[0]['url']);
    }

    public function test_mints_license_less_when_no_content_block_has_server(): void
    {
        $fakeToken = $this->createFakeJwt(['exp' => time() + 3600]);
        $posts = [];

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->method('get')
            ->willReturn(['statusCode' => 200, 'body' => self::SERVERLESS_XML]);
        $httpClient->method('post')
            ->willReturnCallback(function (string $url, string $body) use (&$posts, $fakeToken) {
                $posts[] = ['url' => $url, 'body' => $body];

                return ['statusCode' => 200, 'body' => json_encode(['access_token' => $fakeToken])];
            });

        $client = new LicenseTokenClient($httpClient);
        $token = $client->obtainLicenseToken(self::CLIENT_ID, self::CLIENT_SECRET, self::RESOURCE_URL);

        $this->assertSame($fakeToken, $token);
        $this->assertCount(1, $posts);
        $this->assertSame('https://api-connect.supertab.co/token', $posts[0]['url']);

        parse_str($posts[0]['body'], $params);
        $this->assertArrayNotHasKey('license', $params);
    }

    public function test_shares_agreement_token_across_resources_on_same_origin(): void
    {
        $fakeToken = $this->createFakeJwt(['exp' => time() + 3600]);
        $posts = [];

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->method('get')
            ->willReturn(['statusCode' => 200, 'body' => self::NON_MATCHING_XML]);
        $httpClient->method('post')
            ->willReturnCallback(function (string $url, string $body) use (&$posts, $fakeToken) {
                $posts[] = ['url' => $url, 'body' => $body];

                return ['statusCode' => 200, 'body' => json_encode(['access_token' => $fakeToken])];
            });

        $client = new LicenseTokenClient($httpClient);
        $client->obtainLicenseToken(self::CLIENT_ID, self::CLIENT_SECRET, 'http://127.0.0.1:7676/article/foo');
        $client->obtainLicenseToken(self::CLIENT_ID, self::CLIENT_SECRET, 'http://127.0.0.1:7676/news/bar');

        // Both fall to the Agreement lane (scope = origin): single mint, second call cached.
        $this->assertCount(1, $posts);
    }

    public function test_does_not_share_agreement_tokens_across_origins(): void
    {
        $fakeToken = $this->createFakeJwt(['exp' => time() + 3600]);
        $posts = [];

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->method('get')
            ->willReturn(['statusCode' => 200, 'body' => self::NON_MATCHING_XML]);
        $httpClient->method('post')
            ->willReturnCallback(function (string $url, string $body) use (&$posts, $fakeToken) {
                $posts[] = ['url' => $url, 'body' => $body];

                return ['statusCode' => 200, 'body' => json_encode(['access_token' => $fakeToken])];
            });

        $client = new LicenseTokenClient($httpClient);
        $client->obtainLicenseToken(self::CLIENT_ID, self::CLIENT_SECRET, 'http://site-a.example/article/foo');
        $client->obtainLicenseToken(self::CLIENT_ID, self::CLIENT_SECRET, 'http://site-b.example/article/foo');

        // Agreement-lane tokens are scoped per origin: two origins, two mints.
        $this->assertCount(2, $posts);
    }

    public function test_reuses_matched_token_across_sibling_paths(): void
    {
        $fakeToken = $this->createFakeJwt(['exp' => time() + 3600]);
        $posts = [];

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->method('get')
            ->willReturn(['statusCode' => 200, 'body' => self::LICENSE_XML]);
        $httpClient->method('post')
            ->willReturnCallback(function (string $url, string $body) use (&$posts, $fakeToken) {
                $posts[] = ['url' => $url, 'body' => $body];

                return ['statusCode' => 200, 'body' => json_encode(['access_token' => $fakeToken])];
            });

        $client = new LicenseTokenClient($httpClient);
        $client->obtainLicenseToken(self::CLIENT_ID, self::CLIENT_SECRET, 'http://127.0.0.1:7676/article/foo');
        $client->obtainLicenseToken(self::CLIENT_ID, self::CLIENT_SECRET, 'http://127.0.0.1:7676/article/bar');

        // Both resources match the same <content> block (scope = its urlPattern): one mint.
        $this->assertCount(1, $posts);
    }

    public function test_lanes_use_distinct_cache_keys(): void
    {
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rsl xmlns="https://rslstandard.org/rsl">
  <content url="http://127.0.0.1:7676/article/*" server="http://127.0.0.1:8787">
    <license type="test">
      <link rel="self" href="http://127.0.0.1:8787/license" />
    </license>
  </content>
</rsl>
XML;
        $fakeToken = $this->createFakeJwt(['exp' => time() + 3600]);
        $posts = [];

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->method('get')
            ->willReturn(['statusCode' => 200, 'body' => $xml]);
        $httpClient->method('post')
            ->willReturnCallback(function (string $url, string $body) use (&$posts, $fakeToken) {
                $posts[] = ['url' => $url, 'body' => $body];

                return ['statusCode' => 200, 'body' => json_encode(['access_token' => $fakeToken])];
            });

        $client = new LicenseTokenClient($httpClient);
        $client->obtainLicenseToken(self::CLIENT_ID, self::CLIENT_SECRET, 'http://127.0.0.1:7676/article/foo');
        $client->obtainLicenseToken(self::CLIENT_ID, self::CLIENT_SECRET, 'http://127.0.0.1:7676/premium/foo');

        // Matched lane and Agreement lane on the same origin mint separately.
        $this->assertCount(2, $posts);
        $this->assertSame('http://127.0.0.1:8787/token', $posts[0]['url']);
        $this->assertSame('https://api-connect.supertab.co/token', $posts[1]['url']);

        parse_str($posts[0]['body'], $matchedParams);
        parse_str($posts[1]['body'], $agreementParams);
        $this->assertArrayHasKey('license', $matchedParams);
        $this->assertArrayNotHasKey('license', $agreementParams);
    }

    public function test_prefers_supertab_hosted_block_over_more_specific_third_party_match(): void
    {
        // The third-party block is more specific, but credentials and the
        // license chunk must go to the configured Supertab host when a
        // Supertab-hosted block also matches.
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rsl>
  <content url="http://127.0.0.1:7676/article/*" server="http://third-party.example/urn:sys:evil">
    <license type="test"><link rel="self" /></license>
  </content>
  <content url="http://127.0.0.1:7676/*" server="http://api-connect.test/urn:sys:123">
    <license type="test"><link rel="self" /></license>
  </content>
</rsl>
XML;
        $fakeToken = $this->createFakeJwt(['exp' => time() + 3600]);
        $posts = [];

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->method('get')
            ->willReturn(['statusCode' => 200, 'body' => $xml]);
        $httpClient->method('post')
            ->willReturnCallback(function (string $url, string $body) use (&$posts, $fakeToken) {
                $posts[] = ['url' => $url, 'body' => $body];

                return ['statusCode' => 200, 'body' => json_encode(['access_token' => $fakeToken])];
            });

        $client = new LicenseTokenClient($httpClient, supertabBaseUrl: 'http://api-connect.test');
        $client->obtainLicenseToken(self::CLIENT_ID, self::CLIENT_SECRET, self::RESOURCE_URL);

        $this->assertCount(1, $posts);
        $this->assertSame('http://api-connect.test/urn:sys:123/token', $posts[0]['url']);
    }

    public function test_falls_back_to_third_party_match_when_no_supertab_block_matches(): void
    {
        // LICENSE_XML's server (127.0.0.1:8787) is not the configured Supertab
        // host, so the best match among all providers wins.
        $fakeToken = $this->createFakeJwt(['exp' => time() + 3600]);
        $posts = [];

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->method('get')
            ->willReturn(['statusCode' => 200, 'body' => self::LICENSE_XML]);
        $httpClient->method('post')
            ->willReturnCallback(function (string $url, string $body) use (&$posts, $fakeToken) {
                $posts[] = ['url' => $url, 'body' => $body];

                return ['statusCode' => 200, 'body' => json_encode(['access_token' => $fakeToken])];
            });

        $client = new LicenseTokenClient($httpClient, supertabBaseUrl: 'http://api-connect.test');
        $client->obtainLicenseToken(self::CLIENT_ID, self::CLIENT_SECRET, self::RESOURCE_URL);

        $this->assertCount(1, $posts);
        $this->assertSame('http://127.0.0.1:8787/token', $posts[0]['url']);
    }

    public function test_canonicalizes_default_port_in_agreement_scope_and_license_fetch(): void
    {
        $fakeToken = $this->createFakeJwt(['exp' => time() + 3600]);
        $gets = [];
        $posts = [];

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->method('get')
            ->willReturnCallback(function (string $url) use (&$gets) {
                $gets[] = $url;

                return ['statusCode' => 200, 'body' => self::NON_MATCHING_XML];
            });
        $httpClient->method('post')
            ->willReturnCallback(function (string $url, string $body) use (&$posts, $fakeToken) {
                $posts[] = ['url' => $url, 'body' => $body];

                return ['statusCode' => 200, 'body' => json_encode(['access_token' => $fakeToken])];
            });

        $client = new LicenseTokenClient($httpClient);
        $client->obtainLicenseToken(self::CLIENT_ID, self::CLIENT_SECRET, 'https://example.com/article/foo');
        $client->obtainLicenseToken(self::CLIENT_ID, self::CLIENT_SECRET, 'https://example.com:443/article/bar');

        // Same origin either way: one Agreement mint, canonical license.xml URL.
        $this->assertCount(1, $posts);
        $this->assertSame(['https://example.com/license.xml', 'https://example.com/license.xml'], $gets);
    }

    public function test_canonicalized_resource_url_still_takes_matched_lane(): void
    {
        // Uppercase host and explicit default port must not push a licensed
        // resource onto the Agreement lane.
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rsl>
  <content url="http://example.com/*" server="http://127.0.0.1:8787">
    <license type="test"><link rel="self" /></license>
  </content>
</rsl>
XML;
        $fakeToken = $this->createFakeJwt(['exp' => time() + 3600]);
        $posts = [];

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->method('get')
            ->willReturn(['statusCode' => 200, 'body' => $xml]);
        $httpClient->method('post')
            ->willReturnCallback(function (string $url, string $body) use (&$posts, $fakeToken) {
                $posts[] = ['url' => $url, 'body' => $body];

                return ['statusCode' => 200, 'body' => json_encode(['access_token' => $fakeToken])];
            });

        $client = new LicenseTokenClient($httpClient);
        $client->obtainLicenseToken(self::CLIENT_ID, self::CLIENT_SECRET, 'HTTP://EXAMPLE.COM:80/article/foo');

        $this->assertCount(1, $posts);
        $this->assertSame('http://127.0.0.1:8787/token', $posts[0]['url']);

        parse_str($posts[0]['body'], $params);
        $this->assertArrayHasKey('license', $params);
    }

    public function test_keeps_non_default_port_in_agreement_scope(): void
    {
        $fakeToken = $this->createFakeJwt(['exp' => time() + 3600]);
        $posts = [];

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->method('get')
            ->willReturn(['statusCode' => 200, 'body' => self::NON_MATCHING_XML]);
        $httpClient->method('post')
            ->willReturnCallback(function (string $url, string $body) use (&$posts, $fakeToken) {
                $posts[] = ['url' => $url, 'body' => $body];

                return ['statusCode' => 200, 'body' => json_encode(['access_token' => $fakeToken])];
            });

        $client = new LicenseTokenClient($httpClient);
        $client->obtainLicenseToken(self::CLIENT_ID, self::CLIENT_SECRET, 'https://example.com/article/foo');
        $client->obtainLicenseToken(self::CLIENT_ID, self::CLIENT_SECRET, 'https://example.com:8443/article/foo');

        // Distinct origins: two mints.
        $this->assertCount(2, $posts);
    }

    public function test_normalizes_trailing_slash_server_for_endpoint_and_cache_key(): void
    {
        // Same effective server written with and without a trailing slash on
        // two origins, same path-only pattern: one cache entry, one mint.
        $xmlWithSlash = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rsl>
  <content url="/articles/*" server="http://127.0.0.1:8787/">
    <license type="test"><link rel="self" /></license>
  </content>
</rsl>
XML;
        $xmlWithoutSlash = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rsl>
  <content url="/articles/*" server="http://127.0.0.1:8787">
    <license type="test"><link rel="self" /></license>
  </content>
</rsl>
XML;
        $fakeToken = $this->createFakeJwt(['exp' => time() + 3600]);
        $posts = [];

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->method('get')
            ->willReturnCallback(fn (string $url) => [
                'statusCode' => 200,
                'body' => str_contains($url, 'site-a') ? $xmlWithSlash : $xmlWithoutSlash,
            ]);
        $httpClient->method('post')
            ->willReturnCallback(function (string $url, string $body) use (&$posts, $fakeToken) {
                $posts[] = ['url' => $url, 'body' => $body];

                return ['statusCode' => 200, 'body' => json_encode(['access_token' => $fakeToken])];
            });

        $client = new LicenseTokenClient($httpClient);
        $client->obtainLicenseToken(self::CLIENT_ID, self::CLIENT_SECRET, 'http://site-a.example/articles/foo');
        $client->obtainLicenseToken(self::CLIENT_ID, self::CLIENT_SECRET, 'http://site-b.example/articles/bar');

        $this->assertCount(1, $posts);
        $this->assertSame('http://127.0.0.1:8787/token', $posts[0]['url']);
    }

    public function test_throws_when_resource_url_has_no_origin(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects($this->never())->method('get');
        $httpClient->expects($this->never())->method('post');

        $client = new LicenseTokenClient($httpClient);

        $this->expectException(SupertabConnectException::class);
        $this->expectExceptionMessage('Invalid resource URL');

        $client->obtainLicenseToken(self::CLIENT_ID, self::CLIENT_SECRET, 'not-a-url');
    }

    public function test_throws_on_token_endpoint_failure(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->method('get')
            ->willReturn(['statusCode' => 200, 'body' => self::LICENSE_XML]);
        $httpClient->method('post')
            ->willReturn(['statusCode' => 500, 'body' => 'Internal Server Error']);

        $client = new LicenseTokenClient($httpClient);

        $this->expectException(SupertabConnectException::class);
        $this->expectExceptionMessage('Failed to obtain license token: 500');

        $client->obtainLicenseToken(self::CLIENT_ID, self::CLIENT_SECRET, self::RESOURCE_URL);
    }

    public function test_throws_on_invalid_json_response(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->method('get')
            ->willReturn(['statusCode' => 200, 'body' => self::LICENSE_XML]);
        $httpClient->method('post')
            ->willReturn(['statusCode' => 200, 'body' => 'not json']);

        $client = new LicenseTokenClient($httpClient);

        $this->expectException(SupertabConnectException::class);
        $this->expectExceptionMessage('Failed to parse license token response as JSON');

        $client->obtainLicenseToken(self::CLIENT_ID, self::CLIENT_SECRET, self::RESOURCE_URL);
    }

    public function test_throws_when_access_token_missing(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->method('get')
            ->willReturn(['statusCode' => 200, 'body' => self::LICENSE_XML]);
        $httpClient->method('post')
            ->willReturn(['statusCode' => 200, 'body' => json_encode(['token_type' => 'bearer'])]);

        $client = new LicenseTokenClient($httpClient);

        $this->expectException(SupertabConnectException::class);
        $this->expectExceptionMessage('License token response missing access_token');

        $client->obtainLicenseToken(self::CLIENT_ID, self::CLIENT_SECRET, self::RESOURCE_URL);
    }

    public function test_sends_correct_authorization_header(): void
    {
        $fakeToken = $this->createFakeJwt(['exp' => time() + 3600]);
        $expectedAuth = 'Basic ' . base64_encode(self::CLIENT_ID . ':' . self::CLIENT_SECRET);

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->method('get')
            ->willReturn(['statusCode' => 200, 'body' => self::LICENSE_XML]);
        $httpClient->expects($this->once())
            ->method('post')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->callback(function (array $headers) use ($expectedAuth) {
                    return ($headers['Authorization'] ?? null) === $expectedAuth;
                }),
            )
            ->willReturn(['statusCode' => 200, 'body' => json_encode(['access_token' => $fakeToken])]);

        $client = new LicenseTokenClient($httpClient);
        $client->obtainLicenseToken(self::CLIENT_ID, self::CLIENT_SECRET, self::RESOURCE_URL);
    }

    public function test_sends_correct_form_body(): void
    {
        $fakeToken = $this->createFakeJwt(['exp' => time() + 3600]);

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->method('get')
            ->willReturn(['statusCode' => 200, 'body' => self::LICENSE_XML]);
        $httpClient->expects($this->once())
            ->method('post')
            ->with(
                $this->equalTo('http://127.0.0.1:8787/token'),
                $this->callback(function (string $body) {
                    parse_str($body, $params);

                    return ($params['grant_type'] ?? null) === 'client_credentials'
                        && isset($params['license'])
                        && isset($params['resource']);
                }),
                $this->anything(),
            )
            ->willReturn(['statusCode' => 200, 'body' => json_encode(['access_token' => $fakeToken])]);

        $client = new LicenseTokenClient($httpClient);
        $client->obtainLicenseToken(self::CLIENT_ID, self::CLIENT_SECRET, self::RESOURCE_URL);
    }

    public function test_matched_lane_sends_raw_resource_url_and_license_chunk(): void
    {
        $fakeToken = $this->createFakeJwt(['exp' => time() + 3600]);

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->method('get')
            ->willReturn(['statusCode' => 200, 'body' => self::LICENSE_XML]);
        $httpClient->expects($this->once())
            ->method('post')
            ->with(
                $this->equalTo('http://127.0.0.1:8787/token'),
                $this->callback(function (string $body) {
                    parse_str($body, $params);

                    // Resource is the raw resource URL, not the matched urlPattern
                    return ($params['resource'] ?? null) === self::RESOURCE_URL
                        && str_contains($params['license'] ?? '', '<license');
                }),
                $this->anything(),
            )
            ->willReturn(['statusCode' => 200, 'body' => json_encode(['access_token' => $fakeToken])]);

        $client = new LicenseTokenClient($httpClient);
        $client->obtainLicenseToken(self::CLIENT_ID, self::CLIENT_SECRET, self::RESOURCE_URL);
    }

    public function test_path_only_matched_lane_sends_raw_resource_url(): void
    {
        $fakeToken = $this->createFakeJwt(['exp' => time() + 3600]);

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->method('get')
            ->willReturn(['statusCode' => 200, 'body' => self::LICENSE_XML_PATH_ONLY]);
        $httpClient->expects($this->once())
            ->method('post')
            ->with(
                $this->anything(),
                $this->callback(function (string $body) {
                    parse_str($body, $params);

                    return ($params['resource'] ?? null) === self::RESOURCE_URL;
                }),
                $this->anything(),
            )
            ->willReturn(['statusCode' => 200, 'body' => json_encode(['access_token' => $fakeToken])]);

        $client = new LicenseTokenClient($httpClient);
        $token = $client->obtainLicenseToken(self::CLIENT_ID, self::CLIENT_SECRET, self::RESOURCE_URL);

        $this->assertSame($fakeToken, $token);
    }

    /**
     * Create a fake JWT with the given payload for testing.
     *
     * @param  array<string, mixed>  $payload
     */
    private function createFakeJwt(array $payload): string
    {
        $header = $this->base64UrlEncode(json_encode(['alg' => 'ES256', 'typ' => 'JWT']));
        $body = $this->base64UrlEncode(json_encode($payload));
        $signature = $this->base64UrlEncode('fake-signature');

        return "{$header}.{$body}.{$signature}";
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
