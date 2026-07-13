<?php

declare(strict_types=1);

namespace Supertab\Connect\Tests\Status;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use PHPUnit\Framework\TestCase;
use Supertab\Connect\Exception\JwksKeyNotFoundException;
use Supertab\Connect\Jwks\JwksProviderInterface;
use Supertab\Connect\Status\StatusChallengeVerifier;

final class StatusChallengeVerifierTest extends TestCase
{
    private const AUDIENCE = 'https://acme.com';

    private \OpenSSLAsymmetricKey $privateKey;

    private \OpenSSLAsymmetricKey $publicKey;

    private string $kid;

    protected function setUp(): void
    {
        $keyResource = openssl_pkey_new([
            'curve_name' => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ]);

        $this->privateKey = $keyResource;

        $details = openssl_pkey_get_details($keyResource);
        $this->publicKey = openssl_pkey_get_public($details['key']);

        $this->kid = 'test-key-' . bin2hex(random_bytes(4));
    }

    public function test_accepts_a_valid_challenge(): void
    {
        $token = $this->createToken([
            'purpose' => 'status-probe',
            'aud' => self::AUDIENCE,
            'exp' => time() + 60,
        ]);

        $this->assertTrue($this->createVerifier()->verify($token, self::AUDIENCE));
    }

    public function test_accepts_a_valid_challenge_with_array_audience(): void
    {
        $token = $this->createToken([
            'purpose' => 'status-probe',
            'aud' => ['https://other.com', self::AUDIENCE],
            'exp' => time() + 60,
        ]);

        $this->assertTrue($this->createVerifier()->verify($token, self::AUDIENCE));
    }

    public function test_rejects_wrong_purpose(): void
    {
        $token = $this->createToken([
            'purpose' => 'something-else',
            'aud' => self::AUDIENCE,
            'exp' => time() + 60,
        ]);

        $this->assertFalse($this->createVerifier()->verify($token, self::AUDIENCE));
    }

    public function test_rejects_missing_purpose(): void
    {
        $token = $this->createToken([
            'aud' => self::AUDIENCE,
            'exp' => time() + 60,
        ]);

        $this->assertFalse($this->createVerifier()->verify($token, self::AUDIENCE));
    }

    public function test_rejects_wrong_audience(): void
    {
        $token = $this->createToken([
            'purpose' => 'status-probe',
            'aud' => 'https://evil.com',
            'exp' => time() + 60,
        ]);

        $this->assertFalse($this->createVerifier()->verify($token, self::AUDIENCE));
    }

    public function test_rejects_audience_prefix_only_match(): void
    {
        // Audience must match the origin exactly, not by prefix.
        $token = $this->createToken([
            'purpose' => 'status-probe',
            'aud' => 'https://acme.com/status',
            'exp' => time() + 60,
        ]);

        $this->assertFalse($this->createVerifier()->verify($token, self::AUDIENCE));
    }

    public function test_rejects_an_expired_challenge(): void
    {
        $token = $this->createToken([
            'purpose' => 'status-probe',
            'aud' => self::AUDIENCE,
            'exp' => time() - 30, // beyond the 5s probe leeway
        ]);

        $this->assertFalse($this->createVerifier()->verify($token, self::AUDIENCE));
    }

    public function test_rejects_empty_token(): void
    {
        $this->assertFalse($this->createVerifier()->verify('', self::AUDIENCE));
    }

    public function test_rejects_garbage_token(): void
    {
        $this->assertFalse($this->createVerifier()->verify('not-a-jwt', self::AUDIENCE));
    }

    public function test_rejects_non_es256_algorithm(): void
    {
        $header = $this->base64UrlEncode((string) json_encode(['alg' => 'HS256', 'typ' => 'JWT', 'kid' => $this->kid]));
        $payload = $this->base64UrlEncode((string) json_encode([
            'purpose' => 'status-probe',
            'aud' => self::AUDIENCE,
            'exp' => time() + 60,
        ]));
        $token = "{$header}.{$payload}.fake-signature";

        $this->assertFalse($this->createVerifier()->verify($token, self::AUDIENCE));
    }

    public function test_rejects_tampered_signature(): void
    {
        $token = $this->createToken([
            'purpose' => 'status-probe',
            'aud' => self::AUDIENCE,
            'exp' => time() + 60,
        ]);

        $parts = explode('.', $token);
        $sig = $parts[2];
        $parts[2] = $sig[0] === 'A' ? 'B' . substr($sig, 1) : 'A' . substr($sig, 1);
        $tampered = implode('.', $parts);

        $this->assertFalse($this->createVerifier()->verify($tampered, self::AUDIENCE));
    }

    public function test_retries_with_refreshed_jwks_after_key_rotation(): void
    {
        // Sign with a freshly rotated key absent from the stale cache.
        $newKey = openssl_pkey_new([
            'curve_name' => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ]);
        $newDetails = openssl_pkey_get_details($newKey);
        $newPublicKey = openssl_pkey_get_public($newDetails['key']);
        $newKid = 'new-key-' . bin2hex(random_bytes(4));

        $token = JWT::encode(
            ['purpose' => 'status-probe', 'aud' => self::AUDIENCE, 'exp' => time() + 60],
            $newKey,
            'ES256',
            $newKid,
        );

        $jwksProvider = $this->createMock(JwksProviderInterface::class);
        $newKeys = [$newKid => new Key($newPublicKey, 'ES256')];

        $jwksProvider->method('getKeyByKid')
            ->willReturnCallback(function (string $kid, bool $forceRefresh) use ($newKeys) {
                // Stale cache (forceRefresh=false) lacks the rotated key.
                $keys = $forceRefresh ? $newKeys : [];
                if (! isset($keys[$kid])) {
                    throw new JwksKeyNotFoundException($kid);
                }

                return $keys[$kid];
            });

        $jwksProvider->expects($this->once())->method('clearCache');

        $verifier = new StatusChallengeVerifier($jwksProvider);

        $this->assertTrue($verifier->verify($token, self::AUDIENCE));
    }

    public function test_resolves_false_when_both_fetches_lack_the_signing_key(): void
    {
        $token = $this->createToken([
            'purpose' => 'status-probe',
            'aud' => self::AUDIENCE,
            'exp' => time() + 60,
        ]);

        $jwksProvider = $this->createMock(JwksProviderInterface::class);
        $jwksProvider->method('getKeyByKid')
            ->willReturnCallback(function (string $kid) {
                throw new JwksKeyNotFoundException($kid);
            });
        $jwksProvider->expects($this->once())->method('clearCache');

        $verifier = new StatusChallengeVerifier($jwksProvider);

        // Retry exhausted must resolve to false, never throw.
        $this->assertFalse($verifier->verify($token, self::AUDIENCE));
    }

    // --- Helpers ---

    private function createVerifier(): StatusChallengeVerifier
    {
        $jwksProvider = $this->createMock(JwksProviderInterface::class);

        $jwksProvider->method('getKeyByKid')
            ->willReturnCallback(function (string $kid) {
                if ($kid === $this->kid) {
                    return new Key($this->publicKey, 'ES256');
                }
                throw new JwksKeyNotFoundException($kid);
            });

        return new StatusChallengeVerifier($jwksProvider);
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
