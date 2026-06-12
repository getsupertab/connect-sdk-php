<?php

declare(strict_types=1);

namespace Supertab\Connect\Analytics;

use Supertab\Connect\Analytics\Enum\FinalAction;
use Supertab\Connect\Analytics\Enum\TokenOutcome;

/**
 * Immutable analytics event payload sent to the Supertab Connect relay.
 */
final class AnalyticsEvent
{
    public const SCHEMA_VERSION = 1;

    public function __construct(
        public readonly string $timestamp,
        public readonly string $requestId,
        public readonly string $sourceCdn,
        public readonly string $userAgent,
        public readonly string $clientIp,
        public readonly string $path,
        public readonly string $method,
        public readonly string $referer,
        public readonly string $acceptLanguage,
        public readonly ?string $requestCountry,
        public readonly ?int $requestAsn,
        public readonly ?string $tlsFingerprint,
        public readonly bool $hasToken,
        public readonly TokenOutcome $tokenOutcome,
        public readonly FinalAction $finalAction,
        public readonly string $enforcementMode,
        public readonly ?string $signatureAgent,
        public readonly ?string $signatureInput,
        public readonly ?string $signature,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'timestamp' => $this->timestamp,
            'request_id' => $this->requestId,
            'schema_version' => self::SCHEMA_VERSION,
            'source_cdn' => $this->sourceCdn,
            'user_agent' => $this->userAgent,
            'client_ip' => $this->clientIp,
            'path' => $this->path,
            'method' => $this->method,
            'referer' => $this->referer,
            'accept_language' => $this->acceptLanguage,
            'request_country' => $this->requestCountry,
            'request_asn' => $this->requestAsn,
            'tls_fingerprint' => $this->tlsFingerprint,
            'has_token' => $this->hasToken,
            'token_outcome' => $this->tokenOutcome->value,
            'final_action' => $this->finalAction->value,
            'enforcement_mode' => $this->enforcementMode,
            'signature_agent' => $this->signatureAgent,
            'signature_input' => $this->signatureInput,
            'signature' => $this->signature,
        ];
    }
}
