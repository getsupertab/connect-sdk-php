<?php

declare(strict_types=1);

namespace Supertab\Connect\Tests\License;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use PHPUnit\Framework\TestCase;
use Supertab\Connect\Enum\LicenseTokenInvalidReason;
use Supertab\Connect\Exception\HttpException;
use Supertab\Connect\Exception\JwksKeyNotFoundException;
use Supertab\Connect\Jwks\JwksProviderInterface;
use Supertab\Connect\License\LicenseTokenVerifier;

final class LicenseTokenVerifierTest extends TestCase
{
    private const BASE_URL = 'https://api-connect.supertab.co';

    private const RESOURCE_URL = 'https://example.com/premium-article';

    private \OpenSSLAsymmetricKey $privateKey;

    private \OpenSSLAsymmetricKey $publicKey;

    private string $kid;

    protected function setUp(): void
    {
        // Generate an EC P-256 keypair for testing
        $keyResource = openssl_pkey_new([
            'curve_name' => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ]);

        $this->privateKey = $keyResource;

        $details = openssl_pkey_get_details($keyResource);
        $this->publicKey = openssl_pkey_get_public($details['key']);

        $this->kid = 'test-key-' . bin2hex(random_bytes(4));
    }

    public function test_valid_token(): void
    {
        $token = $this->createToken([
            'iss' => self::BASE_URL . '/some-path',
            'aud' => 'https://example.com',
            'license_id' => 'lic_123',
            'exp' => time() + 3600,
        ]);

        $verifier = $this->createVerifier();
        $result = $verifier->verify($token, self::RESOURCE_URL);

        $this->assertTrue($result->valid);
        $this->assertNull($result->reason);
        $this->assertEquals('lic_123', $result->licenseId);
    }

    public function test_missing_token(): void
    {
        $verifier = $this->createVerifier();
        $result = $verifier->verify('', self::RESOURCE_URL);

        $this->assertFalse($result->valid);
        $this->assertSame(LicenseTokenInvalidReason::MISSING_TOKEN, $result->reason);
    }

    public function test_invalid_header(): void
    {
        $verifier = $this->createVerifier();
        $result = $verifier->verify('not-a-jwt', self::RESOURCE_URL);

        $this->assertFalse($result->valid);
        $this->assertSame(LicenseTokenInvalidReason::INVALID_HEADER, $result->reason);
    }

    public function test_invalid_header_not_base64(): void
    {
        $verifier = $this->createVerifier();
        $result = $verifier->verify('!!!.payload.signature', self::RESOURCE_URL);

        $this->assertFalse($result->valid);
        $this->assertSame(LicenseTokenInvalidReason::INVALID_HEADER, $result->reason);
    }

    public function test_invalid_algorithm(): void
    {
        // Create a token with RS256 header
        $header = $this->base64UrlEncode(json_encode(['alg' => 'RS256', 'typ' => 'JWT', 'kid' => $this->kid]));
        $payload = $this->base64UrlEncode(json_encode([
            'iss' => self::BASE_URL,
            'aud' => self::RESOURCE_URL,
            'exp' => time() + 3600,
        ]));
        $token = "{$header}.{$payload}.fake-signature";

        $verifier = $this->createVerifier();
        $result = $verifier->verify($token, self::RESOURCE_URL);

        $this->assertFalse($result->valid);
        $this->assertSame(LicenseTokenInvalidReason::INVALID_ALG, $result->reason);
    }

    public function test_invalid_payload(): void
    {
        $header = $this->base64UrlEncode(json_encode(['alg' => 'ES256', 'typ' => 'JWT', 'kid' => $this->kid]));
        $payload = $this->base64UrlEncode('not-json');
        $token = "{$header}.{$payload}.fake-signature";

        $verifier = $this->createVerifier();
        $result = $verifier->verify($token, self::RESOURCE_URL);

        $this->assertFalse($result->valid);
        $this->assertSame(LicenseTokenInvalidReason::INVALID_PAYLOAD, $result->reason);
    }

    public function test_invalid_issuer(): void
    {
        $token = $this->createToken([
            'iss' => 'https://evil.example.com',
            'aud' => self::RESOURCE_URL,
            'exp' => time() + 3600,
        ]);

        $verifier = $this->createVerifier();
        $result = $verifier->verify($token, self::RESOURCE_URL);

        $this->assertFalse($result->valid);
        $this->assertSame(LicenseTokenInvalidReason::INVALID_ISSUER, $result->reason);
    }

    public function test_missing_issuer(): void
    {
        $token = $this->createToken([
            'aud' => self::RESOURCE_URL,
            'exp' => time() + 3600,
        ]);

        $verifier = $this->createVerifier();
        $result = $verifier->verify($token, self::RESOURCE_URL);

        $this->assertFalse($result->valid);
        $this->assertSame(LicenseTokenInvalidReason::INVALID_ISSUER, $result->reason);
    }

    public function test_invalid_audience(): void
    {
        $token = $this->createToken([
            'iss' => self::BASE_URL,
            'aud' => 'https://other-site.com',
            'exp' => time() + 3600,
        ]);

        $verifier = $this->createVerifier();
        $result = $verifier->verify($token, self::RESOURCE_URL);

        $this->assertFalse($result->valid);
        $this->assertSame(LicenseTokenInvalidReason::INVALID_AUDIENCE, $result->reason);
    }

    public function test_audience_prefix_match(): void
    {
        // The audience is a prefix — it should match any URL starting with it
        $token = $this->createToken([
            'iss' => self::BASE_URL,
            'aud' => 'https://example.com',
            'exp' => time() + 3600,
        ]);

        $verifier = $this->createVerifier();
        $result = $verifier->verify($token, 'https://example.com/premium-article/page-2');

        $this->assertTrue($result->valid);
    }

    public function test_multiple_audiences(): void
    {
        $token = $this->createToken([
            'iss' => self::BASE_URL,
            'aud' => ['https://other.com', 'https://example.com'],
            'exp' => time() + 3600,
        ]);

        $verifier = $this->createVerifier();
        $result = $verifier->verify($token, self::RESOURCE_URL);

        $this->assertTrue($result->valid);
    }

    public function test_expired_token(): void
    {
        $token = $this->createToken([
            'iss' => self::BASE_URL,
            'aud' => 'https://example.com',
            'exp' => time() - 120, // Expired 2 minutes ago (beyond 60s leeway)
        ]);

        $verifier = $this->createVerifier();
        $result = $verifier->verify($token, self::RESOURCE_URL);

        $this->assertFalse($result->valid);
        $this->assertSame(LicenseTokenInvalidReason::EXPIRED, $result->reason);
    }

    public function test_expired_token_within_leeway(): void
    {
        $token = $this->createToken([
            'iss' => self::BASE_URL,
            'aud' => 'https://example.com',
            'exp' => time() - 30, // Expired 30 seconds ago (within 60s leeway)
        ]);

        $verifier = $this->createVerifier();
        $result = $verifier->verify($token, self::RESOURCE_URL);

        $this->assertTrue($result->valid);
    }

    public function test_invalid_signature(): void
    {
        // Create a valid token, then tamper with the signature
        $token = $this->createToken([
            'iss' => self::BASE_URL,
            'aud' => 'https://example.com',
            'exp' => time() + 3600,
        ]);

        // Tamper: flip a character in the signature
        $parts = explode('.', $token);
        $sig = $parts[2];
        $parts[2] = $sig[0] === 'A' ? 'B' . substr($sig, 1) : 'A' . substr($sig, 1);
        $tamperedToken = implode('.', $parts);

        $verifier = $this->createVerifier();
        $result = $verifier->verify($tamperedToken, self::RESOURCE_URL);

        $this->assertFalse($result->valid);
        $this->assertSame(LicenseTokenInvalidReason::SIGNATURE_VERIFICATION_FAILED, $result->reason);
    }

    public function test_key_rotation_retry(): void
    {
        // Generate a second keypair (simulating key rotation)
        $newKey = openssl_pkey_new([
            'curve_name' => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ]);
        $newDetails = openssl_pkey_get_details($newKey);
        $newPublicKey = openssl_pkey_get_public($newDetails['key']);
        $newKid = 'new-key-' . bin2hex(random_bytes(4));

        // Sign token with the NEW key
        $token = JWT::encode(
            [
                'iss' => self::BASE_URL,
                'aud' => 'https://example.com',
                'license_id' => 'lic_rotated',
                'exp' => time() + 3600,
            ],
            $newKey,
            'ES256',
            $newKid,
        );

        // Create a JWKS provider mock that returns old keys first, then both keys on refresh
        $jwksProvider = $this->createMock(JwksProviderInterface::class);

        $oldKeys = [$this->kid => new Key($this->publicKey, 'ES256')];
        $newKeys = [
            $this->kid => new Key($this->publicKey, 'ES256'),
            $newKid => new Key($newPublicKey, 'ES256'),
        ];

        // First call: getKeyByKid with the new kid fails (not in old keys)
        // After cache clear: getKeyByKid with forceRefresh returns the new key
        $callCount = 0;
        $jwksProvider->method('getKeyByKid')
            ->willReturnCallback(function (string $kid, bool $forceRefresh) use ($oldKeys, $newKeys, &$callCount) {
                $callCount++;
                $keys = $forceRefresh ? $newKeys : $oldKeys;

                if (! isset($keys[$kid])) {
                    throw new JwksKeyNotFoundException($kid);
                }

                return $keys[$kid];
            });

        $jwksProvider->expects($this->once())->method('clearCache');

        $verifier = new LicenseTokenVerifier($jwksProvider, self::BASE_URL);
        $result = $verifier->verify($token, self::RESOURCE_URL);

        $this->assertTrue($result->valid);
        $this->assertEquals('lic_rotated', $result->licenseId);
    }

    public function test_server_error_on_jwks_fetch_failure(): void
    {
        $token = $this->createToken([
            'iss' => self::BASE_URL,
            'aud' => 'https://example.com',
            'exp' => time() + 3600,
        ]);

        $jwksProvider = $this->createMock(JwksProviderInterface::class);
        $jwksProvider->method('getKeyByKid')
            ->willThrowException(new HttpException('Network error'));

        $verifier = new LicenseTokenVerifier($jwksProvider, self::BASE_URL);
        $result = $verifier->verify($token, self::RESOURCE_URL);

        $this->assertFalse($result->valid);
        $this->assertSame(LicenseTokenInvalidReason::SERVER_ERROR, $result->reason);
    }

    public function test_trailing_slash_normalization(): void
    {
        $token = $this->createToken([
            'iss' => self::BASE_URL . '/',
            'aud' => 'https://example.com/',
            'exp' => time() + 3600,
        ]);

        $verifier = $this->createVerifier();
        $result = $verifier->verify($token, 'https://example.com/premium-article/');

        $this->assertTrue($result->valid);
    }

    // --- Helpers ---

    private function createVerifier(): LicenseTokenVerifier
    {
        $jwksProvider = $this->createMock(JwksProviderInterface::class);

        $jwksProvider->method('getKeyByKid')
            ->willReturnCallback(function (string $kid) {
                if ($kid === $this->kid) {
                    return new Key($this->publicKey, 'ES256');
                }
                throw new JwksKeyNotFoundException($kid);
            });

        $jwksProvider->method('getKeys')
            ->willReturn([$this->kid => new Key($this->publicKey, 'ES256')]);

        return new LicenseTokenVerifier($jwksProvider, self::BASE_URL);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function createToken(array $payload): string
    {
        return JWT::encode($payload, $this->privateKey, 'ES256', $this->kid);
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
