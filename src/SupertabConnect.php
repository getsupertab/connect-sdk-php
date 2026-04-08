<?php

declare(strict_types=1);

namespace Supertab\Connect;

use Supertab\Connect\Bot\BotDetectorInterface;
use Supertab\Connect\Cache\CacheInterface;
use Supertab\Connect\Customer\LicenseTokenClient;
use Supertab\Connect\Enum\EnforcementMode;
use Supertab\Connect\Enum\LicenseTokenInvalidReason;
use Supertab\Connect\Event\EventRecorder;
use Supertab\Connect\Exception\SupertabConnectException;
use Supertab\Connect\Http\HttpClient;
use Supertab\Connect\Http\HttpClientInterface;
use Supertab\Connect\Http\RequestContext;
use Supertab\Connect\Jwks\JwksProvider;
use Supertab\Connect\License\LicenseTokenVerifier;
use Supertab\Connect\License\ResponseBuilder;
use Supertab\Connect\Result\AllowResult;
use Supertab\Connect\Result\HandlerResult;
use Supertab\Connect\Result\VerificationResult;

final class SupertabConnect
{
    private static string $baseUrl = 'https://api-connect.supertab.co';

    private static ?self $instance = null;

    private readonly LicenseTokenVerifier $verifier;

    private readonly EventRecorder $eventRecorder;

    private readonly ?BotDetectorInterface $botDetector;

    /**
     * Create a new SupertabConnect instance (singleton).
     *
     * Returns the existing instance if one exists with the same apiKey.
     * Throws if an instance with a different apiKey already exists (unless $reset is true).
     */
    public function __construct(
        private readonly string $apiKey,
        private readonly EnforcementMode $enforcement = EnforcementMode::SOFT,
        private readonly bool $debug = false,
        ?string $baseUrl = null,
        ?HttpClientInterface $httpClient = null,
        ?BotDetectorInterface $botDetector = null,
        ?CacheInterface $cache = null,
    ) {
        if ($this->apiKey === '') {
            throw new \InvalidArgumentException('Missing required configuration: apiKey is required');
        }

        if ($baseUrl !== null) {
            self::$baseUrl = rtrim($baseUrl, '/');
        }

        if (self::$instance !== null) {
            if ($this->apiKey !== self::$instance->apiKey) {
                throw new \LogicException(
                    'Cannot create a new instance with different configuration. Use resetInstance() to clear the existing instance.'
                );
            }
            // Return existing instance — but PHP constructors can't return a different object,
            // so we copy internal dependencies from the existing instance instead.
            $this->verifier = self::$instance->verifier;
            $this->eventRecorder = self::$instance->eventRecorder;
            $this->botDetector = self::$instance->botDetector;

            return;
        }

        $client = $httpClient ?? new HttpClient;
        $jwksProvider = new JwksProvider(self::$baseUrl, $client, $this->debug, $cache);
        $this->verifier = new LicenseTokenVerifier($jwksProvider, self::$baseUrl, $this->debug);
        $this->eventRecorder = new EventRecorder($this->apiKey, self::$baseUrl, $client, $this->debug);
        $this->botDetector = $botDetector ?? null;

        self::$instance = $this;
    }

    /**
     * Clear the singleton instance, allowing a new one to be created with different config.
     */
    public static function resetInstance(): void
    {
        self::$instance = null;
    }

    /**
     * Override the default base URL for API requests (for development/testing).
     */
    public static function setBaseUrl(string $url): void
    {
        self::$baseUrl = rtrim($url, '/');
    }

    /**
     * Get the current base URL for API requests.
     */
    public static function getBaseUrl(): string
    {
        return self::$baseUrl;
    }

    /**
     * Pure token verification — verifies a license token without recording any events.
     * Does not require a SupertabConnect instance.
     */
    public static function verify(
        string $token,
        string $resourceUrl,
        ?string $baseUrl = null,
        bool $debug = false,
        ?HttpClientInterface $httpClient = null,
        ?CacheInterface $cache = null,
    ): VerificationResult {
        $effectiveBaseUrl = $baseUrl ?? self::$baseUrl;
        $client = $httpClient ?? new HttpClient;
        $jwksProvider = new JwksProvider($effectiveBaseUrl, $client, $debug, $cache);
        $verifier = new LicenseTokenVerifier($jwksProvider, $effectiveBaseUrl, $debug);

        $result = $verifier->verify($token, $resourceUrl);

        if ($result->valid) {
            return new VerificationResult(valid: true);
        }

        return new VerificationResult(valid: false, error: $result->error);
    }

    /**
     * Verify a license token and record an analytics event.
     * Uses the instance's apiKey for event recording.
     */
    public function verifyAndRecord(
        string $token,
        string $resourceUrl,
        ?string $userAgent = null,
    ): VerificationResult {
        $verification = $this->verifier->verify($token, $resourceUrl);

        $this->eventRecorder->record(
            eventName: $verification->valid ? 'license_used' : $verification->reason->value,
            properties: [
                'page_url' => $resourceUrl,
                'user_agent' => $userAgent ?? 'unknown',
                'verification_status' => $verification->valid ? 'valid' : 'invalid',
                'verification_reason' => $verification->valid ? 'success' : $verification->reason->value,
            ],
            licenseId: $verification->licenseId,
        );

        if ($verification->valid) {
            return new VerificationResult(valid: true);
        }

        return new VerificationResult(valid: false, error: $verification->error);
    }

    /**
     * Fetch the RSL license XML for a merchant from the Supertab Connect API.
     *
     * Mirrors the TypeScript SDK's hostRSLicenseXML() function. The endpoint is public
     * (no authentication required). Does not require a SupertabConnect instance.
     *
     * @throws SupertabConnectException on non-200 response or network failure
     */
    public static function fetchLicenseXml(
        string $merchantSystemUrn,
        ?string $baseUrl = null,
        ?HttpClientInterface $httpClient = null,
    ): string {
        $effectiveBaseUrl = $baseUrl !== null ? rtrim($baseUrl, '/') : self::$baseUrl;
        $client = $httpClient ?? new HttpClient;

        $url = "{$effectiveBaseUrl}/merchants/systems/{$merchantSystemUrn}/license.xml";
        $response = $client->get($url);

        if ($response['statusCode'] !== 200) {
            throw new SupertabConnectException(
                "Failed to fetch license XML: HTTP {$response['statusCode']}"
            );
        }

        return $response['body'];
    }

    /**
     * Obtain a license token for accessing a protected resource.
     *
     * Uses the OAuth2 client_credentials flow via the resource's license.xml.
     * Does not require a SupertabConnect instance.
     *
     * @throws SupertabConnectException on any failure
     */
    public static function obtainLicenseToken(
        string $clientId,
        string $clientSecret,
        string $resourceUrl,
        bool $debug = false,
        ?HttpClientInterface $httpClient = null,
    ): string {
        $client = new LicenseTokenClient(
            httpClient: $httpClient ?? new HttpClient,
            debug: $debug,
        );

        return $client->obtainLicenseToken($clientId, $clientSecret, $resourceUrl);
    }

    /**
     * Handle an incoming request by extracting the license token, verifying it,
     * recording analytics events, and applying enforcement mode with bot detection.
     *
     * When no RequestContext is provided, reads from $_SERVER superglobals.
     */
    public function handleRequest(?RequestContext $context = null): HandlerResult
    {
        $context ??= RequestContext::fromGlobals();

        $auth = $context->authorizationHeader ?? '';
        $token = str_starts_with($auth, 'License ') ? substr($auth, 8) : null;
        $url = $context->url;

        // Token present → always validate, regardless of mode or bot detection
        if ($token !== null) {
            if ($this->enforcement === EnforcementMode::DISABLED) {
                return new AllowResult;
            }

            $verification = $this->verifier->verify($token, $url);

            // Record event (fire-and-forget)
            $this->eventRecorder->record(
                eventName: $verification->valid ? 'license_used' : $verification->reason->value,
                properties: [
                    'page_url' => $url,
                    'user_agent' => $context->userAgent ?? 'unknown',
                    'verification_status' => $verification->valid ? 'valid' : 'invalid',
                    'verification_reason' => $verification->valid ? 'success' : $verification->reason->value,
                ],
                licenseId: $verification->licenseId,
            );

            if (! $verification->valid) {
                return ResponseBuilder::buildBlockResult(
                    reason: $verification->reason,
                    error: $verification->error,
                    requestUrl: $url,
                );
            }

            return new AllowResult;
        }

        // No token — run bot detection
        $isBot = $this->botDetector?->isBot($context) ?? false;

        if (! $isBot) {
            return new AllowResult;
        }

        // Bot detected, no token — enforcement mode decides
        return match ($this->enforcement) {
            EnforcementMode::STRICT => ResponseBuilder::buildBlockResult(
                reason: LicenseTokenInvalidReason::MISSING_TOKEN,
                error: LicenseTokenInvalidReason::MISSING_TOKEN->toErrorDescription(),
                requestUrl: $url,
            ),
            EnforcementMode::SOFT => ResponseBuilder::buildSignalResult($url),
            EnforcementMode::DISABLED => new AllowResult,
        };
    }
}
