<?php

declare(strict_types=1);

namespace Supertab\Connect\Analytics;

use Supertab\Connect\Http\HttpClientInterface;

/**
 * Analytics transport that POSTs events to the Supertab Connect relay at
 * `{baseUrl}/ingest/events`.
 *
 * Authenticated with the merchant API key (`Authorization: Bearer <apiKey>`);
 * the backend derives merchant identity from the key, so no merchant identifier
 * is sent in the payload. Fail-open: all errors are swallowed (logged only in
 * debug mode), mirroring {@see \Supertab\Connect\Event\EventRecorder}. Isolated
 * from the billing `/events` path.
 */
final class HttpAnalyticsTransport implements AnalyticsTransportInterface
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $baseUrl,
        private readonly HttpClientInterface $httpClient,
        private readonly bool $debug = false,
    ) {}

    public function emit(AnalyticsEvent $event): void
    {
        try {
            $response = $this->httpClient->post(
                rtrim($this->baseUrl, '/') . self::ANALYTICS_EVENTS_PATH,
                json_encode($event->toArray(), JSON_THROW_ON_ERROR),
                [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type' => 'application/json',
                ],
            );

            if ($this->debug && ($response['statusCode'] < 200 || $response['statusCode'] >= 300)) {
                error_log('[SupertabConnect] Failed to emit analytics event: ' . $response['statusCode']);
            }
        } catch (\Throwable $e) {
            if ($this->debug) {
                error_log('[SupertabConnect] Error emitting analytics event: ' . $e->getMessage());
            }
        }
    }
}
