<?php

declare(strict_types=1);

namespace Supertab\Connect\Http;

final class RequestContext
{
    public function __construct(
        public string $url,
        public ?string $authorizationHeader = null,
        public ?string $userAgent = null,
        public ?string $accept = null,
        public ?string $acceptLanguage = null,
        public ?string $secChUa = null,
    ) {}

    /**
     * Build a RequestContext from PHP's $_SERVER superglobal.
     */
    public static function fromGlobals(): self
    {
        $scheme = (! empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $url = "{$scheme}://{$host}{$uri}";

        // PHP strips the Authorization header in some setups; check multiple sources
        $authorization = $_SERVER['HTTP_AUTHORIZATION']
            ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
            ?? null;

        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
        $accept = $_SERVER['HTTP_ACCEPT'] ?? null;
        $acceptLanguage = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? null;
        $secChUa = $_SERVER['HTTP_SEC_CH_UA'] ?? null;

        return new self(
            url: $url,
            authorizationHeader: $authorization,
            userAgent: $userAgent,
            accept: $accept,
            acceptLanguage: $acceptLanguage,
            secChUa: $secChUa,
        );
    }
}
