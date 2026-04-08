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

    public function test_uses_path_only_url_pattern_as_resource_param(): void
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

                    // Path-only pattern should be sent as-is
                    return ($params['resource'] ?? null) === '/*';
                }),
                $this->anything(),
            )
            ->willReturn(['statusCode' => 200, 'body' => json_encode(['access_token' => $fakeToken])]);

        $client = new LicenseTokenClient($httpClient);
        $token = $client->obtainLicenseToken(self::CLIENT_ID, self::CLIENT_SECRET, self::RESOURCE_URL);

        $this->assertSame($fakeToken, $token);
    }

    // --- generateLicenseToken tests ---

    public function test_generate_license_token_with_ec_key(): void
    {
        $fakeToken = $this->createFakeJwt(['exp' => time() + 3600]);
        $ecKey = $this->generateEcKey();

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects($this->never())->method('get');
        $httpClient->expects($this->once())
            ->method('post')
            ->willReturn(['statusCode' => 200, 'body' => json_encode(['access_token' => $fakeToken])]);

        $client = new LicenseTokenClient($httpClient);
        $token = $client->generateLicenseToken(
            self::CLIENT_ID,
            'key-1',
            $ecKey,
            self::RESOURCE_URL,
            self::LICENSE_XML,
        );

        $this->assertSame($fakeToken, $token);
    }

    public function test_generate_license_token_with_rsa_key(): void
    {
        $fakeToken = $this->createFakeJwt(['exp' => time() + 3600]);
        $rsaKey = $this->generateRsaKey();

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects($this->once())
            ->method('post')
            ->willReturn(['statusCode' => 200, 'body' => json_encode(['access_token' => $fakeToken])]);

        $client = new LicenseTokenClient($httpClient);
        $token = $client->generateLicenseToken(
            self::CLIENT_ID,
            'key-1',
            $rsaKey,
            self::RESOURCE_URL,
            self::LICENSE_XML,
        );

        $this->assertSame($fakeToken, $token);
    }

    public function test_generate_license_token_sends_correct_form_body(): void
    {
        $fakeToken = $this->createFakeJwt(['exp' => time() + 3600]);
        $ecKey = $this->generateEcKey();

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects($this->once())
            ->method('post')
            ->with(
                $this->equalTo('http://127.0.0.1:8787/token'),
                $this->callback(function (string $body) {
                    parse_str($body, $params);

                    return ($params['grant_type'] ?? null) === 'rsl'
                        && ($params['client_assertion_type'] ?? null) === 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer'
                        && isset($params['client_assertion'])
                        && isset($params['license'])
                        && ($params['resource'] ?? null) === 'http://127.0.0.1:7676/*';
                }),
                $this->anything(),
            )
            ->willReturn(['statusCode' => 200, 'body' => json_encode(['access_token' => $fakeToken])]);

        $client = new LicenseTokenClient($httpClient);
        $client->generateLicenseToken(
            self::CLIENT_ID,
            'key-1',
            $ecKey,
            self::RESOURCE_URL,
            self::LICENSE_XML,
        );
    }

    public function test_generate_license_token_sends_no_authorization_header(): void
    {
        $fakeToken = $this->createFakeJwt(['exp' => time() + 3600]);
        $ecKey = $this->generateEcKey();

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects($this->once())
            ->method('post')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->callback(function (array $headers) {
                    return ! isset($headers['Authorization']);
                }),
            )
            ->willReturn(['statusCode' => 200, 'body' => json_encode(['access_token' => $fakeToken])]);

        $client = new LicenseTokenClient($httpClient);
        $client->generateLicenseToken(
            self::CLIENT_ID,
            'key-1',
            $ecKey,
            self::RESOURCE_URL,
            self::LICENSE_XML,
        );
    }

    public function test_generate_license_token_jwt_claims_with_ec_key(): void
    {
        $fakeToken = $this->createFakeJwt(['exp' => time() + 3600]);
        $ecKey = $this->generateEcKey();
        $kid = 'my-key-id';

        $capturedBody = null;
        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects($this->once())
            ->method('post')
            ->with(
                $this->anything(),
                $this->callback(function (string $body) use (&$capturedBody) {
                    $capturedBody = $body;

                    return true;
                }),
                $this->anything(),
            )
            ->willReturn(['statusCode' => 200, 'body' => json_encode(['access_token' => $fakeToken])]);

        $client = new LicenseTokenClient($httpClient);
        $client->generateLicenseToken(
            self::CLIENT_ID,
            $kid,
            $ecKey,
            self::RESOURCE_URL,
            self::LICENSE_XML,
        );

        // Decode the client_assertion JWT
        parse_str($capturedBody, $params);
        $assertion = $params['client_assertion'];
        $segments = explode('.', $assertion);
        $this->assertCount(3, $segments);

        $header = json_decode($this->base64UrlDecode($segments[0]), true);
        $payload = json_decode($this->base64UrlDecode($segments[1]), true);

        // Verify header
        $this->assertSame('ES256', $header['alg']);
        $this->assertSame($kid, $header['kid']);

        // Verify payload claims
        $this->assertSame(self::CLIENT_ID, $payload['iss']);
        $this->assertSame(self::CLIENT_ID, $payload['sub']);
        $this->assertSame('http://127.0.0.1:8787/token', $payload['aud']);
        $this->assertIsInt($payload['iat']);
        $this->assertEqualsWithDelta(time(), $payload['iat'], 5);
        $this->assertSame($payload['iat'] + 300, $payload['exp']);
    }

    public function test_generate_license_token_jwt_claims_with_rsa_key(): void
    {
        $fakeToken = $this->createFakeJwt(['exp' => time() + 3600]);
        $rsaKey = $this->generateRsaKey();

        $capturedBody = null;
        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects($this->once())
            ->method('post')
            ->with(
                $this->anything(),
                $this->callback(function (string $body) use (&$capturedBody) {
                    $capturedBody = $body;

                    return true;
                }),
                $this->anything(),
            )
            ->willReturn(['statusCode' => 200, 'body' => json_encode(['access_token' => $fakeToken])]);

        $client = new LicenseTokenClient($httpClient);
        $client->generateLicenseToken(
            self::CLIENT_ID,
            'rsa-key-1',
            $rsaKey,
            self::RESOURCE_URL,
            self::LICENSE_XML,
        );

        parse_str($capturedBody, $params);
        $segments = explode('.', $params['client_assertion']);
        $header = json_decode($this->base64UrlDecode($segments[0]), true);

        $this->assertSame('RS256', $header['alg']);
        $this->assertSame('rsa-key-1', $header['kid']);
    }

    public function test_generate_license_token_parses_license_xml_and_matches(): void
    {
        $fakeToken = $this->createFakeJwt(['exp' => time() + 3600]);
        $ecKey = $this->generateEcKey();

        // Use path-only pattern — should still match
        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects($this->once())
            ->method('post')
            ->with(
                $this->equalTo('http://127.0.0.1:8787/token'),
                $this->anything(),
                $this->anything(),
            )
            ->willReturn(['statusCode' => 200, 'body' => json_encode(['access_token' => $fakeToken])]);

        $client = new LicenseTokenClient($httpClient);
        $token = $client->generateLicenseToken(
            self::CLIENT_ID,
            'key-1',
            $ecKey,
            self::RESOURCE_URL,
            self::LICENSE_XML_PATH_ONLY,
        );

        $this->assertSame($fakeToken, $token);
    }

    public function test_generate_license_token_caches_result(): void
    {
        $fakeToken = $this->createFakeJwt(['exp' => time() + 3600]);
        $ecKey = $this->generateEcKey();

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects($this->once())
            ->method('post')
            ->willReturn(['statusCode' => 200, 'body' => json_encode(['access_token' => $fakeToken])]);

        $client = new LicenseTokenClient($httpClient);

        $token1 = $client->generateLicenseToken(
            self::CLIENT_ID,
            'key-1',
            $ecKey,
            self::RESOURCE_URL,
            self::LICENSE_XML,
        );
        $token2 = $client->generateLicenseToken(
            self::CLIENT_ID,
            'key-1',
            $ecKey,
            self::RESOURCE_URL,
            self::LICENSE_XML,
        );

        $this->assertSame($fakeToken, $token1);
        $this->assertSame($fakeToken, $token2);
    }

    public function test_generate_license_token_throws_on_invalid_key(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $client = new LicenseTokenClient($httpClient);

        $this->expectException(SupertabConnectException::class);
        $this->expectExceptionMessage('Unsupported private key format');

        $client->generateLicenseToken(
            self::CLIENT_ID,
            'key-1',
            'not-a-valid-pem-key',
            self::RESOURCE_URL,
            self::LICENSE_XML,
        );
    }

    public function test_generate_license_token_throws_on_no_matching_content(): void
    {
        $ecKey = $this->generateEcKey();
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rsl>
  <content url="http://other-host.com/*" server="http://token.other.com">
    <license type="test"><link rel="self" /></license>
  </content>
</rsl>
XML;

        $httpClient = $this->createMock(HttpClientInterface::class);
        $client = new LicenseTokenClient($httpClient);

        $this->expectException(SupertabConnectException::class);
        $this->expectExceptionMessage('No <content> element in license.xml matches resource URL');

        $client->generateLicenseToken(
            self::CLIENT_ID,
            'key-1',
            $ecKey,
            self::RESOURCE_URL,
            $xml,
        );
    }

    public function test_generate_license_token_throws_on_endpoint_failure(): void
    {
        $ecKey = $this->generateEcKey();

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->method('post')
            ->willReturn(['statusCode' => 500, 'body' => 'Internal Server Error']);

        $client = new LicenseTokenClient($httpClient);

        $this->expectException(SupertabConnectException::class);
        $this->expectExceptionMessage('Failed to obtain license token: 500');

        $client->generateLicenseToken(
            self::CLIENT_ID,
            'key-1',
            $ecKey,
            self::RESOURCE_URL,
            self::LICENSE_XML,
        );
    }

    // --- Helper methods ---

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

    private function base64UrlDecode(string $data): string
    {
        $remainder = strlen($data) % 4;
        if ($remainder !== 0) {
            $data .= str_repeat('=', 4 - $remainder);
        }

        return base64_decode(strtr($data, '-_', '+/'), true);
    }

    private function generateEcKey(): string
    {
        $key = openssl_pkey_new([
            'curve_name' => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ]);
        openssl_pkey_export($key, $pem);

        return $pem;
    }

    private function generateRsaKey(): string
    {
        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        openssl_pkey_export($key, $pem);

        return $pem;
    }
}
