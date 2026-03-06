<?php

declare(strict_types=1);

namespace Supertab\Connect\Event;

use Supertab\Connect\Http\HttpClientInterface;

/**
 * Event recorder.
 *
 * POSTs analytics events to the Supertab Connect events endpoint.
 * Errors are silently ignored (logged only in debug mode).
 */
final class EventRecorder
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $baseUrl,
        private readonly HttpClientInterface $httpClient,
        private readonly bool $debug = false,
    ) {}

    /**
     * Record an analytics event.
     *
     * @param  array<string, string>  $properties
     */
    public function record(
        string $eventName,
        array $properties,
        ?string $licenseId = null,
    ): void {
        $payload = [
            'event_name' => $eventName,
            'properties' => $properties,
        ];

        if ($licenseId !== null) {
            $payload['license_id'] = $licenseId;
        }

        try {
            $response = $this->httpClient->post(
                rtrim($this->baseUrl, '/') . '/events',
                json_encode($payload, JSON_THROW_ON_ERROR),
                [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type' => 'application/json',
                ],
            );

            if ($this->debug && ($response['statusCode'] < 200 || $response['statusCode'] >= 300)) {
                error_log('[SupertabConnect] Failed to record event: ' . $response['statusCode']);
            }
        } catch (\Throwable $e) {
            if ($this->debug) {
                error_log('[SupertabConnect] Error recording event: ' . $e->getMessage());
            }
        }
    }
}
