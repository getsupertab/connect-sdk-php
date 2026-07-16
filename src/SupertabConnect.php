<?php

declare(strict_types=1);

namespace Supertab\Connect;

use Supertab\Connect\Analytics\AnalyticsEventFactory;
use Supertab\Connect\Analytics\AnalyticsTransportInterface;
use Supertab\Connect\Analytics\Decision;
use Supertab\Connect\Analytics\DeferredAnalyticsTransport;
use Supertab\Connect\Analytics\Enum\FinalAction;
use Supertab\Connect\Analytics\Enum\TokenOutcome;
use Supertab\Connect\Analytics\HttpAnalyticsTransport;
use Supertab\Connect\Analytics\NoopAnalyticsTransport;
use Supertab\Connect\Analytics\TokenOutcomeMapper;
use Supertab\Connect\Bot\BotDetectorInterface;
use Supertab\Connect\Cache\CacheInterface;
use Supertab\Connect\Customer\LicenseTokenClient;
use Supertab\Connect\Enum\EnforcementMode;
use Supertab\Connect\Enum\LicenseTokenInvalidReason;
use Supertab\Connect\Event\EventRecorder;
use Supertab\Connect\Exception\SupertabConnectException;
use Supertab\Connect\Http\Headers;
use Supertab\Connect\Http\HttpClient;
use Supertab\Connect\Http\HttpClientInterface;
use Supertab\Connect\Http\RequestContext;
use Supertab\Connect\Jwks\JwksProvider;
use Supertab\Connect\License\LicenseTokenVerifier;
use Supertab\Connect\License\ResponseBuilder;
use Supertab\Connect\Result\HandlerResult;
use Supertab\Connect\Result\RespondResult;
use Supertab\Connect\Result\VerificationResult;
use Supertab\Connect\Status\StatusChallengeVerifier;

final class SupertabConnect
{
    private const ANALYTICS_TIMEOUT_SECONDS = 1;

    private const STATUS_PATH = '/.well-known/supertab/status';

    private static string $baseUrl = 'https://api-connect.supertab.co';

    /**
     * Analytics is served by the dedicated ingest service, not the API host.
     * Kept as a separate static (mirroring $baseUrl/setBaseUrl) so the relay
     * can be pointed at a different host — or at localhost in dev — without
     * moving token/JWKS/verify traffic.
     */
    private static string $analyticsBaseUrl = 'https://ingest-connect.supertab.co';

    private static ?self $instance = null;

    private readonly LicenseTokenVerifier $verifier;

    private readonly StatusChallengeVerifier $statusVerifier;

    private readonly EventRecorder $eventRecorder;

    private readonly ?BotDetectorInterface $botDetector;

    private readonly AnalyticsEventFactory $analyticsEventFactory;

    private readonly AnalyticsTransportInterface $analyticsTransport;

    /**
     * Create a new SupertabConnect instance (singleton).
     *
     * Returns the existing instance if one exists with the same apiKey.
     * Throws if an instance with a different apiKey already exists (unless $reset is true).
     */
    public function __construct(
        private readonly string $apiKey,
        private readonly EnforcementMode $enforcement = EnforcementMode::OBSERVE,
        private readonly bool $debug = false,
        ?string $baseUrl = null,
        ?HttpClientInterface $httpClient = null,
        ?BotDetectorInterface $botDetector = null,
        ?CacheInterface $cache = null,
        bool $analyticsEnabled = false,
        ?AnalyticsTransportInterface $analyticsTransport = null,
        ?string $analyticsBaseUrl = null,
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
            $this->statusVerifier = self::$instance->statusVerifier;
            $this->eventRecorder = self::$instance->eventRecorder;
            $this->botDetector = self::$instance->botDetector;
            $this->analyticsEventFactory = self::$instance->analyticsEventFactory;
            $this->analyticsTransport = self::$instance->analyticsTransport;

            return;
        }

        $client = $httpClient ?? new HttpClient;
        $jwksProvider = new JwksProvider(self::$baseUrl, $client, $this->debug, $cache);
        $this->verifier = new LicenseTokenVerifier($jwksProvider, self::$baseUrl, $this->debug);
        $this->statusVerifier = new StatusChallengeVerifier($jwksProvider, $this->debug);
        $this->eventRecorder = new EventRecorder($this->apiKey, self::$baseUrl, $client, $this->debug);
        $this->botDetector = $botDetector ?? null;
        $this->analyticsEventFactory = new AnalyticsEventFactory;
        $this->analyticsTransport = $this->buildAnalyticsTransport(
            $analyticsTransport,
            $analyticsEnabled,
            $httpClient,
            $analyticsBaseUrl !== null ? rtrim($analyticsBaseUrl, '/') : self::$analyticsBaseUrl,
        );

        self::$instance = $this;
    }

    /**
     * Select the analytics transport. An explicitly injected transport (the
     * internal DI / escape-hatch seam) wins; otherwise a no-op when analytics is
     * disabled, or an HTTP transport when enabled.
     *
     * The HTTP transport reuses the caller-supplied HTTP client so analytics
     * shares the same egress path as the rest of the SDK — important on hosts
     * that block direct outbound connections and require their own client (e.g.
     * WordPress's wp_remote_* API on VIP). Only when no client is injected does
     * it fall back to a default client with a short timeout, keeping emission
     * fail-open and bounded for that case.
     *
     * The default HTTP transport is wrapped in {@see DeferredAnalyticsTransport}
     * so that, on FastCGI-style SAPIs (PHP-FPM, LiteSpeed, FrankenPHP), the POST
     * runs after the response has been flushed to the client instead of on the
     * user-perceived latency path. Where `fastcgi_finish_request()` is absent
     * (mod_php, CLI), the decorator falls back to synchronous delivery. An
     * injected transport is used as-is — it is the seam integrators use to route
     * analytics through their own deferral (e.g. a WordPress Action Scheduler
     * job via {@see CallbackAnalyticsTransport}), so the SDK does not re-wrap it.
     */
    private function buildAnalyticsTransport(
        ?AnalyticsTransportInterface $injected,
        bool $analyticsEnabled,
        ?HttpClientInterface $httpClient,
        string $analyticsBaseUrl,
    ): AnalyticsTransportInterface {
        if ($injected !== null) {
            return $injected;
        }

        if (! $analyticsEnabled) {
            return new NoopAnalyticsTransport;
        }

        $forceSync = $this->shouldForceSyncAnalytics();

        if ($forceSync && $this->debug) {
            error_log('[SupertabConnect] Analytics deferral forced off via SUPERTAB_CONNECT_FORCE_SYNC_ANALYTICS; emitting synchronously.');
        }

        return new DeferredAnalyticsTransport(
            new HttpAnalyticsTransport(
                $this->apiKey,
                $analyticsBaseUrl,
                $httpClient ?? new HttpClient(self::ANALYTICS_TIMEOUT_SECONDS),
                $this->debug,
            ),
            $this->debug,
            deferralAvailable: $forceSync ? false : null,
        );
    }

    /**
     * Benchmarking/diagnostic escape hatch. When the
     * `SUPERTAB_CONNECT_FORCE_SYNC_ANALYTICS` constant is defined truthy, the
     * default analytics transport emits synchronously instead of deferring the
     * POST past response flush on FastCGI SAPIs — letting deferred-vs-sync
     * response latency be compared on the same host.
     *
     * Read from a constant (not a constructor parameter) on purpose, so it
     * stays off the public API surface. Only affects the default transport;
     * an injected transport owns its own deferral.
     */
    private function shouldForceSyncAnalytics(): bool
    {
        return defined('SUPERTAB_CONNECT_FORCE_SYNC_ANALYTICS')
            && filter_var(constant('SUPERTAB_CONNECT_FORCE_SYNC_ANALYTICS'), FILTER_VALIDATE_BOOLEAN);
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
     * Override the base URL of the analytics ingest relay (e.g. for a non-prod
     * environment or local development). Independent of setBaseUrl —
     * token/JWKS/verify traffic is unaffected. Can also be set per-instance via
     * the `analyticsBaseUrl` constructor option, which takes precedence.
     */
    public static function setAnalyticsBaseUrl(string $url): void
    {
        self::$analyticsBaseUrl = rtrim($url, '/');
    }

    /**
     * Get the current base URL of the analytics ingest relay.
     */
    public static function getAnalyticsBaseUrl(): string
    {
        return self::$analyticsBaseUrl;
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
     *
     * @param  array<string, string>|null  $requestHeaders  Raw incoming request headers; merged
     *                                                      into event properties under `h_` prefix
     *                                                      (credentials and PII headers filtered out).
     */
    public function verifyAndRecord(
        string $token,
        string $resourceUrl,
        ?string $userAgent = null,
        ?array $requestHeaders = null,
    ): VerificationResult {
        $verification = $this->verifier->verify($token, $resourceUrl);

        $this->eventRecorder->record(
            eventName: $verification->valid ? 'license_used' : $verification->reason->value,
            properties: [
                'page_url' => $resourceUrl,
                'user_agent' => $userAgent ?? 'unknown',
                'sdk_user_agent' => HttpClient::resolveUserAgent(),
                'verification_status' => $verification->valid ? 'valid' : 'invalid',
                'verification_reason' => $verification->valid ? 'success' : $verification->reason->value,
                ...Headers::toEventProperties($requestHeaders ?? []),
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
     * recording a billing event, emitting one relay analytics event, and
     * applying enforcement mode with bot detection.
     *
     * Exactly one analytics event is emitted per request (across every branch),
     * which is what bot classification needs. Emission is fail-open — it never
     * throws or alters request handling. With the default transport the POST is
     * deferred past response flush on FastCGI SAPIs and otherwise runs
     * synchronously with a bounded timeout. Analytics is off unless enabled.
     *
     * When no RequestContext is provided, reads from $_SERVER superglobals.
     */
    public function handleRequest(?RequestContext $context = null): HandlerResult
    {
        $context ??= RequestContext::fromGlobals();

        $url = $context->url;

        // Self-report status probe short-circuits before token verification, bot
        // detection, and analytics, so a probe never looks like real traffic or
        // emits an event. Cheap substring pre-filter, then exact path match.
        if (
            str_contains($url, self::STATUS_PATH)
            && parse_url($url, PHP_URL_PATH) === self::STATUS_PATH
        ) {
            return $this->handleStatusRequest($context, $url);
        }

        $auth = $context->authorizationHeader ?? '';
        $token = str_starts_with($auth, 'License ') ? substr($auth, 8) : null;
        $hasToken = $token !== null;

        // Build and emit one analytics event for this request. Fail-open: any
        // failure here must never affect the returned HandlerResult.
        $emit = function (TokenOutcome $tokenOutcome, FinalAction $finalAction) use ($context, $hasToken): void {
            try {
                $this->analyticsTransport->emit(
                    $this->analyticsEventFactory->build(
                        $context,
                        new Decision(
                            hasToken: $hasToken,
                            tokenOutcome: $tokenOutcome,
                            finalAction: $finalAction,
                            enforcementMode: $this->enforcement,
                        ),
                    ),
                );
            } catch (\Throwable $e) {
                if ($this->debug) {
                    error_log('[SupertabConnect] Failed to build/emit analytics event: ' . $e->getMessage());
                }
            }
        };

        // Token present → always validate, regardless of mode or bot detection
        if ($token !== null) {
            if ($this->enforcement === EnforcementMode::DISABLED) {
                // DISABLED short-circuits to ALLOW without verifying the token,
                // so we cannot claim "valid" — report "not_validated".
                $emit(TokenOutcome::NOT_VALIDATED, FinalAction::ALLOW);

                return ResponseBuilder::buildAllowResult($url);
            }

            $verification = $this->verifier->verify($token, $url);

            // Record billing event (fire-and-forget)
            $this->eventRecorder->record(
                eventName: $verification->valid ? 'license_used' : $verification->reason->value,
                properties: [
                    'page_url' => $url,
                    'user_agent' => $context->userAgent ?? 'unknown',
                    'sdk_user_agent' => HttpClient::resolveUserAgent(),
                    'verification_status' => $verification->valid ? 'valid' : 'invalid',
                    'verification_reason' => $verification->valid ? 'success' : $verification->reason->value,
                    ...Headers::toEventProperties($context->headers),
                ],
                licenseId: $verification->licenseId,
            );

            $tokenOutcome = $verification->valid
                ? TokenOutcome::VALID
                : ($verification->reason !== null
                    ? TokenOutcomeMapper::fromReason($verification->reason)
                    : TokenOutcome::MALFORMED);

            if (! $verification->valid) {
                $emit($tokenOutcome, FinalAction::BLOCK);

                return ResponseBuilder::buildBlockResult(
                    reason: $verification->reason,
                    error: $verification->error,
                    requestUrl: $url,
                );
            }

            $emit($tokenOutcome, FinalAction::ALLOW);

            return ResponseBuilder::buildAllowResult($url);
        }

        // No token — run bot detection
        $isBot = $this->botDetector?->isBot($context) ?? false;

        if (! $isBot) {
            $emit(TokenOutcome::ABSENT, FinalAction::ALLOW);

            return ResponseBuilder::buildAllowResult($url);
        }

        // Bot detected, no token — enforcement mode decides
        switch ($this->enforcement) {
            case EnforcementMode::ENFORCE:
                $emit(TokenOutcome::ABSENT, FinalAction::BLOCK);

                return ResponseBuilder::buildBlockResult(
                    reason: LicenseTokenInvalidReason::MISSING_TOKEN,
                    error: LicenseTokenInvalidReason::MISSING_TOKEN->toErrorDescription(),
                    requestUrl: $url,
                );
            case EnforcementMode::OBSERVE:
                $emit(TokenOutcome::ABSENT, FinalAction::OBSERVE);

                return ResponseBuilder::buildSignalResult($url);
            default: // DISABLED
                $emit(TokenOutcome::ABSENT, FinalAction::ALLOW);

                return ResponseBuilder::buildAllowResult($url);
        }
    }

    /**
     * Serve the self-report status endpoint. A request carrying a valid,
     * backend-minted ES256 challenge (Authorization: Bearer, purpose
     * "status-probe", aud = the request origin) gets the live running config
     * back; anything else gets a minimal `{ "supertab": true }` 404. Both set
     * `Cache-Control: no-store`.
     *
     * `runtime` and `merchantUrn` are omitted (emitted as null / left out) until
     * they are plumbed through the request context.
     */
    private function handleStatusRequest(RequestContext $context, string $url): RespondResult
    {
        $headers = [
            'Content-Type' => 'application/json',
            'Cache-Control' => 'no-store',
        ];

        $auth = $context->authorizationHeader ?? '';
        $token = str_starts_with($auth, 'Bearer ') ? substr($auth, 7) : '';

        $ok = $token !== ''
            && $this->statusVerifier->verify($token, $this->originFromUrl($url));

        if (! $ok) {
            return new RespondResult(
                status: 404,
                body: (string) json_encode(['supertab' => true]),
                headers: $headers,
            );
        }

        return new RespondResult(
            status: 200,
            body: (string) json_encode([
                'runtime' => null,
                'sdkVersion' => HttpClient::resolveVersion(),
                // Self-describing component identity: the backend maps the kind
                // to the right update registry (Packagist for php-sdk), instead
                // of legacy-shimming a bare sdkVersion to ts-sdk/npm.
                'component' => [
                    'kind' => 'php-sdk',
                    'version' => HttpClient::resolveVersion(),
                ],
                'enforcement' => $this->enforcement->value,
                // Reflect whether events will actually be emitted, derived from
                // the effective transport rather than the config flag: an
                // injected transport enables reporting regardless of the flag,
                // and it is the transport (not the flag) that is copied on
                // singleton reuse.
                'eventReporting' => ! ($this->analyticsTransport instanceof NoopAnalyticsTransport),
            ]),
            headers: $headers,
        );
    }

    /**
     * Derive the request origin (scheme://host[:port]) used as the expected
     * challenge audience. Returns an empty string when the URL is unparseable,
     * which no valid challenge audience can match.
     */
    private function originFromUrl(string $url): string
    {
        $parsed = parse_url($url);

        if ($parsed === false || ! isset($parsed['scheme'], $parsed['host'])) {
            return '';
        }

        $port = isset($parsed['port']) ? ":{$parsed['port']}" : '';

        return "{$parsed['scheme']}://{$parsed['host']}{$port}";
    }
}
