<?php

declare(strict_types=1);

namespace Supertab\Connect\Tests\Event;

use PHPUnit\Framework\TestCase;
use Supertab\Connect\Event\EventRecorder;
use Supertab\Connect\Exception\HttpException;
use Supertab\Connect\Http\HttpClientInterface;

final class EventRecorderTest extends TestCase
{
    public function test_records_event_with_correct_payload(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects($this->once())
            ->method('post')
            ->with(
                'https://api-connect.supertab.co/events',
                $this->callback(function (string $body): bool {
                    $data = json_decode($body, true);

                    return $data['event_name'] === 'license_used'
                        && $data['properties']['page_url'] === 'https://example.com/article'
                        && $data['properties']['user_agent'] === 'TestBot/1.0'
                        && ! array_key_exists('license_id', $data);
                }),
                $this->callback(function (array $headers): bool {
                    return $headers['Authorization'] === 'Bearer test-api-key'
                        && $headers['Content-Type'] === 'application/json';
                }),
            )
            ->willReturn(['statusCode' => 200, 'body' => '']);

        $recorder = new EventRecorder(
            apiKey: 'test-api-key',
            baseUrl: 'https://api-connect.supertab.co',
            httpClient: $httpClient,
        );

        $recorder->record(
            eventName: 'license_used',
            properties: [
                'page_url' => 'https://example.com/article',
                'user_agent' => 'TestBot/1.0',
            ],
        );
    }

    public function test_includes_license_id_when_provided(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects($this->once())
            ->method('post')
            ->with(
                $this->anything(),
                $this->callback(function (string $body): bool {
                    $data = json_decode($body, true);

                    return $data['license_id'] === 'lic_abc123';
                }),
                $this->anything(),
            )
            ->willReturn(['statusCode' => 200, 'body' => '']);

        $recorder = new EventRecorder(
            apiKey: 'test-api-key',
            baseUrl: 'https://api-connect.supertab.co',
            httpClient: $httpClient,
        );

        $recorder->record(
            eventName: 'license_used',
            properties: ['page_url' => 'https://example.com'],
            licenseId: 'lic_abc123',
        );
    }

    public function test_omits_license_id_when_null(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects($this->once())
            ->method('post')
            ->with(
                $this->anything(),
                $this->callback(function (string $body): bool {
                    $data = json_decode($body, true);

                    return ! array_key_exists('license_id', $data);
                }),
                $this->anything(),
            )
            ->willReturn(['statusCode' => 200, 'body' => '']);

        $recorder = new EventRecorder(
            apiKey: 'test-api-key',
            baseUrl: 'https://api-connect.supertab.co',
            httpClient: $httpClient,
        );

        $recorder->record(
            eventName: 'license_used',
            properties: ['page_url' => 'https://example.com'],
        );
    }

    public function test_swallows_http_errors(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->method('post')
            ->willReturn(['statusCode' => 500, 'body' => 'Internal Server Error']);

        $recorder = new EventRecorder(
            apiKey: 'test-api-key',
            baseUrl: 'https://api-connect.supertab.co',
            httpClient: $httpClient,
        );

        // Should not throw
        $recorder->record(
            eventName: 'license_used',
            properties: ['page_url' => 'https://example.com'],
        );

        $this->assertTrue(true); // Reached without exception
    }

    public function test_swallows_network_exceptions(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->method('post')
            ->willThrowException(new HttpException('Connection refused', 0));

        $recorder = new EventRecorder(
            apiKey: 'test-api-key',
            baseUrl: 'https://api-connect.supertab.co',
            httpClient: $httpClient,
        );

        // Should not throw
        $recorder->record(
            eventName: 'license_used',
            properties: ['page_url' => 'https://example.com'],
        );

        $this->assertTrue(true); // Reached without exception
    }

    public function test_posts_to_events_endpoint(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects($this->once())
            ->method('post')
            ->with(
                'https://custom-api.example.com/events',
                $this->anything(),
                $this->anything(),
            )
            ->willReturn(['statusCode' => 200, 'body' => '']);

        $recorder = new EventRecorder(
            apiKey: 'test-api-key',
            baseUrl: 'https://custom-api.example.com',
            httpClient: $httpClient,
        );

        $recorder->record(
            eventName: 'license_used',
            properties: ['page_url' => 'https://example.com'],
        );
    }

    public function test_strips_trailing_slash_from_base_url(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects($this->once())
            ->method('post')
            ->with(
                'https://api-connect.supertab.co/events',
                $this->anything(),
                $this->anything(),
            )
            ->willReturn(['statusCode' => 200, 'body' => '']);

        $recorder = new EventRecorder(
            apiKey: 'test-api-key',
            baseUrl: 'https://api-connect.supertab.co/',
            httpClient: $httpClient,
        );

        $recorder->record(
            eventName: 'license_used',
            properties: ['page_url' => 'https://example.com'],
        );
    }
}
