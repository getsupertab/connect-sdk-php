<?php

declare(strict_types=1);

namespace Supertab\Connect;

use Supertab\Connect\Customer\LicenseTokenClient;
use Supertab\Connect\Enum\EnforcementMode;
use Supertab\Connect\Enum\LicenseTokenInvalidReason;
use Supertab\Connect\Exception\SupertabConnectException;
use Supertab\Connect\Http\HttpClient;
use Supertab\Connect\Http\HttpClientInterface;
use Supertab\Connect\Http\RequestContext;
use Supertab\Connect\Jwks\JwksProvider;
use Supertab\Connect\License\LicenseTokenVerifier;
use Supertab\Connect\License\ResponseBuilder;
use Supertab\Connect\Result\AllowResult;
use Supertab\Connect\Result\BlockResult;
use Supertab\Connect\Result\HandlerResult;
use Supertab\Connect\Result\VerificationResult;

final class SupertabConnect
{
    private static string $baseUrl = 'https://api-connect.supertab.co';

    private static ?self $instance = null;

    private readonly LicenseTokenVerifier $verifier;

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
            // so we copy the verifier from the existing instance instead.
            $this->verifier = self::$instance->verifier;

            return;
        }

        $client = $httpClient ?? new HttpClient;
        $jwksProvider = new JwksProvider(self::$baseUrl, $client, $this->debug);
        $this->verifier = new LicenseTokenVerifier($jwksProvider, self::$baseUrl, $this->debug);

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
    ): VerificationResult {
        $effectiveBaseUrl = $baseUrl ?? self::$baseUrl;
        $client = $httpClient ?? new HttpClient;
        $jwksProvider = new JwksProvider($effectiveBaseUrl, $client, $debug);
        $verifier = new LicenseTokenVerifier($jwksProvider, $effectiveBaseUrl, $debug);

        $result = $verifier->verify($token, $resourceUrl);

        if ($result->valid) {
            return new VerificationResult(valid: true);
        }

        return new VerificationResult(valid: false, error: $result->error);
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
     * and applying enforcement mode.
     *
     * When no RequestContext is provided, reads from $_SERVER superglobals.
     */
    public function handleRequest(?RequestContext $context = null): HandlerResult
    {
        $context ??= RequestContext::fromGlobals();

        $auth = $context->authorizationHeader ?? '';
        $token = str_starts_with($auth, 'License ') ? substr($auth, 8) : null;
        $url = $context->url;

        // Token present → always validate regardless of mode
        if ($token !== null) {
            if ($this->enforcement === EnforcementMode::DISABLED) {
                return $this->send(new AllowResult);
            }

            $verification = $this->verifier->verify($token, $url);

            if (! $verification->valid) {
                return $this->send(ResponseBuilder::buildBlockResult(
                    reason: $verification->reason,
                    error: $verification->error,
                    requestUrl: $url,
                ));
            }

            return $this->send(new AllowResult);
        }

        // No token — enforcement mode decides
        return $this->send(match ($this->enforcement) {
            EnforcementMode::STRICT => ResponseBuilder::buildBlockResult(
                reason: LicenseTokenInvalidReason::MISSING_TOKEN,
                error: LicenseTokenInvalidReason::MISSING_TOKEN->toErrorDescription(),
                requestUrl: $url,
            ),
            EnforcementMode::SOFT => ResponseBuilder::buildSignalResult($url),
            EnforcementMode::DISABLED => new AllowResult,
        });
    }

    private function send(HandlerResult $result): HandlerResult
    {
        foreach ($result->headers as $name => $value) {
            header("{$name}: {$value}");
        }

        if ($result instanceof BlockResult) {
            http_response_code($result->status);
        }

        return $result;
    }
}
