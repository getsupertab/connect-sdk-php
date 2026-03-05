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
        // GET for license.xml should be called exactly once
        $httpClient->expects($this->once())
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

    public function test_throws_when_no_matching_content(): void
    {
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rsl>
  <content url="http://other-host.com/*" server="http://token.other.com">
    <license type="test"><link rel="self" /></license>
  </content>
</rsl>
XML;

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->method('get')
            ->willReturn(['statusCode' => 200, 'body' => $xml]);

        $client = new LicenseTokenClient($httpClient);

        $this->expectException(SupertabConnectException::class);
        $this->expectExceptionMessage('No <content> element in license.xml matches resource URL');

        $client->obtainLicenseToken(self::CLIENT_ID, self::CLIENT_SECRET, self::RESOURCE_URL);
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

    public function test_uses_url_pattern_as_resource_param(): void
    {
        $fakeToken = $this->createFakeJwt(['exp' => time() + 3600]);

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->method('get')
            ->willReturn(['statusCode' => 200, 'body' => self::LICENSE_XML]);
        $httpClient->expects($this->once())
            ->method('post')
            ->with(
                $this->anything(),
                $this->callback(function (string $body) {
                    parse_str($body, $params);

                    // Resource should be the URL pattern from license.xml, not the original resource URL
                    return ($params['resource'] ?? null) === 'http://127.0.0.1:7676/*';
                }),
                $this->anything(),
            )
            ->willReturn(['statusCode' => 200, 'body' => json_encode(['access_token' => $fakeToken])]);

        $client = new LicenseTokenClient($httpClient);
        $client->obtainLicenseToken(self::CLIENT_ID, self::CLIENT_SECRET, self::RESOURCE_URL);
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
