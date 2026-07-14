<?php

declare(strict_types=1);

namespace Supertab\Connect\Tests;

use Firebase\JWT\JWT;
use PHPUnit\Framework\TestCase;
use Supertab\Connect\Analytics\AnalyticsEvent;
use Supertab\Connect\Analytics\AnalyticsTransportInterface;
use Supertab\Connect\Bot\BotDetectorInterface;
use Supertab\Connect\Enum\EnforcementMode;
use Supertab\Connect\Enum\HandlerAction;
use Supertab\Connect\Http\HttpClientInterface;
use Supertab\Connect\Http\RequestContext;
use Supertab\Connect\Result\RespondResult;
use Supertab\Connect\SupertabConnect;

final class SupertabConnectStatusTest extends TestCase
{
    private const STATUS_URL = 'https://acme.com/.well-known/supertab/status';

    private const ORIGIN = 'https://acme.com';

    private \OpenSSLAsymmetricKey $privateKey;

    private string $kid;

    /** @var array<int, array<string, mixed>> */
    private array $jwks;

    protected function setUp(): void
    {
        SupertabConnect::resetInstance();
        SupertabConnect::setBaseUrl('https://api-connect.supertab.co');

        $keyResource = openssl_pkey_new([
            'curve_name' => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ]);
        $this->privateKey = $keyResource;
        $this->kid = 'status-key-' . bin2hex(random_bytes(4));
        $this->jwks = ['keys' => [$this->jwkFromKey($keyResource, $this->kid)]];
    }

    protected function tearDown(): void
    {
        SupertabConnect::resetInstance();
        SupertabConnect::setBaseUrl('https://api-connect.supertab.co');
    }

    public function test_valid_challenge_returns_200_with_live_config(): void
    {
        $captured = [];
        $stc = new SupertabConnect(
            apiKey: 'test-key',
            enforcement: EnforcementMode::ENFORCE,
            httpClient: $this->jwksClient(),
            analyticsEnabled: true,
            analyticsTransport: $this->recordingTransport($captured),
        );

        $result = $stc->handleRequest($this->statusContext($this->challenge()));

        $this->assertInstanceOf(RespondResult::class, $result);
        $this->assertSame(HandlerAction::RESPOND, $result->action);
        $this->assertSame(200, $result->status);
        $this->assertSame('no-store', $result->headers['Cache-Control']);
        $this->assertSame('application/json', $result->headers['Content-Type']);

        $payload = json_decode($result->body, true);
        $this->assertNull($payload['runtime']);
        $this->assertArrayHasKey('sdkVersion', $payload);
        $this->assertSame('enforce', $payload['enforcement']);
        $this->assertTrue($payload['eventReporting']);

        // Short-circuit precedes analytics: no event emitted for a probe.
        $this->assertSame([], $captured);
    }

    public function test_event_reporting_reflects_analytics_disabled(): void
    {
        $stc = new SupertabConnect(
            apiKey: 'test-key',
            enforcement: EnforcementMode::OBSERVE,
            httpClient: $this->jwksClient(),
            analyticsEnabled: false,
        );

        $result = $stc->handleRequest($this->statusContext($this->challenge()));

        $this->assertInstanceOf(RespondResult::class, $result);
        $payload = json_decode($result->body, true);
        $this->assertFalse($payload['eventReporting']);
        $this->assertSame('observe', $payload['enforcement']);
    }

    public function test_event_reporting_true_when_transport_injected_despite_flag_off(): void
    {
        // An injected transport emits events regardless of the analyticsEnabled
        // flag (buildAnalyticsTransport returns it before checking the flag), so
        // eventReporting must reflect the effective transport, not the flag.
        $captured = [];
        $stc = new SupertabConnect(
            apiKey: 'test-key',
            httpClient: $this->jwksClient(),
            // analyticsEnabled left at its default false...
            analyticsTransport: $this->recordingTransport($captured), // ...but a transport is injected
        );

        $result = $stc->handleRequest($this->statusContext($this->challenge()));

        $this->assertInstanceOf(RespondResult::class, $result);
        $payload = json_decode($result->body, true);
        $this->assertTrue($payload['eventReporting']);
    }

    public function test_status_payload_carries_php_sdk_component_identity(): void
    {
        // The backend resolves the update registry per component kind
        // (wordpress.org / npm / Packagist); without this field a PHP
        // deployment is legacy-shimmed to ts-sdk and checked against npm.
        $stc = new SupertabConnect(apiKey: 'test-key', httpClient: $this->jwksClient());

        $result = $stc->handleRequest($this->statusContext($this->challenge()));

        $this->assertInstanceOf(RespondResult::class, $result);
        $payload = json_decode($result->body, true);
        $this->assertSame(
            ['kind' => 'php-sdk', 'version' => $payload['sdkVersion']],
            $payload['component'],
        );
    }

    public function test_invalid_challenge_returns_404_minimal_body(): void
    {
        $stc = new SupertabConnect(apiKey: 'test-key', httpClient: $this->jwksClient());

        // A syntactically valid token signed by the wrong key.
        $wrongKey = openssl_pkey_new([
            'curve_name' => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ]);
        $badToken = JWT::encode(
            ['purpose' => 'status-probe', 'aud' => self::ORIGIN, 'exp' => time() + 60],
            $wrongKey,
            'ES256',
            $this->kid,
        );

        $result = $stc->handleRequest($this->statusContext($badToken));

        $this->assertInstanceOf(RespondResult::class, $result);
        $this->assertSame(404, $result->status);
        $this->assertSame('{"supertab":true}', $result->body);
        $this->assertSame('no-store', $result->headers['Cache-Control']);
    }

    public function test_absent_authorization_header_returns_404(): void
    {
        $stc = new SupertabConnect(apiKey: 'test-key', httpClient: $this->jwksClient());

        $result = $stc->handleRequest(new RequestContext(url: self::STATUS_URL));

        $this->assertInstanceOf(RespondResult::class, $result);
        $this->assertSame(404, $result->status);
        $this->assertSame('{"supertab":true}', $result->body);
    }

    public function test_status_branch_precedes_bot_detection(): void
    {
        $botDetector = $this->createMock(BotDetectorInterface::class);
        // The probe short-circuits before bot detection ever runs.
        $botDetector->expects($this->never())->method('isBot');

        $stc = new SupertabConnect(
            apiKey: 'test-key',
            httpClient: $this->jwksClient(),
            botDetector: $botDetector,
        );

        $result = $stc->handleRequest($this->statusContext($this->challenge()));

        $this->assertInstanceOf(RespondResult::class, $result);
        $this->assertSame(200, $result->status);
    }

    // --- Helpers ---

    private function challenge(): string
    {
        return JWT::encode(
            ['purpose' => 'status-probe', 'aud' => self::ORIGIN, 'exp' => time() + 60],
            $this->privateKey,
            'ES256',
            $this->kid,
        );
    }

    private function statusContext(string $token): RequestContext
    {
        return new RequestContext(
            url: self::STATUS_URL,
            authorizationHeader: "Bearer {$token}",
        );
    }

    private function jwksClient(): HttpClientInterface
    {
        $client = $this->createMock(HttpClientInterface::class);
        $client->method('get')->willReturn([
            'statusCode' => 200,
            'body' => (string) json_encode($this->jwks),
        ]);
        // A probe never records billing events or analytics.
        $client->expects($this->never())->method('post');

        return $client;
    }

    /**
     * @param  list<AnalyticsEvent>  $captured
     */
    private function recordingTransport(array &$captured): AnalyticsTransportInterface
    {
        $transport = $this->createMock(AnalyticsTransportInterface::class);
        $transport->method('emit')->willReturnCallback(function (AnalyticsEvent $event) use (&$captured): void {
            $captured[] = $event;
        });

        return $transport;
    }

    /**
     * Build a JWK (kty EC, crv P-256) from an OpenSSL EC keypair's public part.
     *
     * @return array<string, string>
     */
    private function jwkFromKey(\OpenSSLAsymmetricKey $key, string $kid): array
    {
        $details = openssl_pkey_get_details($key);

        return [
            'kty' => 'EC',
            'crv' => 'P-256',
            'x' => $this->base64UrlEncode($details['ec']['x']),
            'y' => $this->base64UrlEncode($details['ec']['y']),
            'kid' => $kid,
            'alg' => 'ES256',
            'use' => 'sig',
        ];
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
