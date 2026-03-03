<?php

declare(strict_types=1);

namespace Supertab\Connect\Http;

use Supertab\Connect\Exception\HttpException;

final class HttpClient implements HttpClientInterface
{
    private const DEFAULT_TIMEOUT = 10;

    private const USER_AGENT = 'SupertabConnect-PHP/1.0';

    public function __construct(
        private readonly int $timeout = self::DEFAULT_TIMEOUT,
    ) {}

    /**
     * @param  array<string, string>  $headers
     * @return array{statusCode: int, body: string}
     *
     * @throws HttpException
     */
    public function get(string $url, array $headers = []): array
    {
        return $this->request('GET', $url, $headers);
    }

    /**
     * @param  array<string, string>  $headers
     * @return array{statusCode: int, body: string}
     *
     * @throws HttpException
     */
    public function post(string $url, string $body, array $headers = []): array
    {
        return $this->request('POST', $url, $headers, $body);
    }

    /**
     * @param  array<string, string>  $headers
     * @return array{statusCode: int, body: string}
     *
     * @throws HttpException
     */
    private function request(string $method, string $url, array $headers, ?string $body = null): array
    {
        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => $this->timeout,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_USERAGENT => self::USER_AGENT,
        ]);

        if ($headers !== []) {
            $formattedHeaders = [];
            foreach ($headers as $name => $value) {
                $formattedHeaders[] = "{$name}: {$value}";
            }
            curl_setopt($ch, CURLOPT_HTTPHEADER, $formattedHeaders);
        }

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($body !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            }
        }

        $responseBody = curl_exec($ch);
        $error = curl_error($ch);
        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($responseBody === false) {
            throw new HttpException("cURL error: {$error}", $statusCode);
        }

        return [
            'statusCode' => $statusCode,
            'body' => (string) $responseBody,
        ];
    }
}
