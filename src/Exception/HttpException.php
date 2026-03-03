<?php

declare(strict_types=1);

namespace Supertab\Connect\Exception;

final class HttpException extends SupertabConnectException
{
    public function __construct(
        string $message,
        public readonly int $statusCode = 0,
        public readonly string $responseBody = '',
    ) {
        parent::__construct($message);
    }
}
