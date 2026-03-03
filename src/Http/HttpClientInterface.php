<?php

declare(strict_types=1);

namespace Supertab\Connect\Http;

use Supertab\Connect\Exception\HttpException;

interface HttpClientInterface
{
    /**
     * @param  array<string, string>  $headers
     * @return array{statusCode: int, body: string}
     *
     * @throws HttpException
     */
    public function get(string $url, array $headers = []): array;

    /**
     * @param  array<string, string>  $headers
     * @return array{statusCode: int, body: string}
     *
     * @throws HttpException
     */
    public function post(string $url, string $body, array $headers = []): array;
}
