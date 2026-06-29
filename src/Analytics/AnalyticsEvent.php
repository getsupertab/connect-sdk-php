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
    public const SCHEMA_VERSION = 2;

    /**
     * @param  list<string>  $headerNames  Lowercased, deduped, sorted request-header names with edge-injected
     *                                      headers stripped. Non-nullable: [] when none.
     */
    public function __construct(
        public readonly string $timestamp,
        public readonly string $requestId,
        public readonly ?string $sourceCdn,
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
        // --- Capture v2 (schema_version 2): portable header signals ---
        public readonly ?string $secFetchMode,
        public readonly ?string $secFetchSite,
        public readonly ?string $secFetchDest,
        public readonly ?string $secFetchUser,
        public readonly ?string $secChUa,
        public readonly ?string $secChUaMobile,
        public readonly ?string $secChUaPlatform,
        public readonly ?string $accept,
        public readonly ?string $host,
        public readonly bool $hasCookies,
        public readonly array $headerNames,
        // Query-string derived signals (the raw query is never stored).
        public readonly ?int $queryLength,
        public readonly ?int $queryParamCount,
        public readonly ?bool $querySuspicious,
        // --- Capture v2: CDN plumbing (null at a PHP origin unless injected) ---
        public readonly ?string $acceptEncoding,
        public readonly ?string $httpProtocol,
        public readonly ?string $tlsVersion,
        public readonly ?string $tlsCipher,
        public readonly ?int $tlsClientHelloLength,
        public readonly ?string $tlsClientExtensionsSha1,
        public readonly ?string $asOrganization,
        public readonly ?int $clientTcpRtt,
        public readonly ?string $cdnVerifiedBotCategory,
        public readonly ?string $requestPriority,
        public readonly ?string $tlsFingerprintJa4,
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

            // --- Capture v2: portable header signals ---
            'sec_fetch_mode' => $this->secFetchMode,
            'sec_fetch_site' => $this->secFetchSite,
            'sec_fetch_dest' => $this->secFetchDest,
            'sec_fetch_user' => $this->secFetchUser,
            'sec_ch_ua' => $this->secChUa,
            'sec_ch_ua_mobile' => $this->secChUaMobile,
            'sec_ch_ua_platform' => $this->secChUaPlatform,
            'accept' => $this->accept,
            'host' => $this->host,
            'has_cookies' => $this->hasCookies,
            'header_names' => $this->headerNames,

            // Query-string derived signals (raw query never stored).
            'query_length' => $this->queryLength,
            'query_param_count' => $this->queryParamCount,
            'query_suspicious' => $this->querySuspicious,

            // --- Capture v2: CDN plumbing ---
            'accept_encoding' => $this->acceptEncoding,
            'http_protocol' => $this->httpProtocol,
            'tls_version' => $this->tlsVersion,
            'tls_cipher' => $this->tlsCipher,
            'tls_client_hello_length' => $this->tlsClientHelloLength,
            'tls_client_extensions_sha1' => $this->tlsClientExtensionsSha1,
            'as_organization' => $this->asOrganization,
            'client_tcp_rtt' => $this->clientTcpRtt,
            'cdn_verified_bot_category' => $this->cdnVerifiedBotCategory,
            'request_priority' => $this->requestPriority,
            'tls_fingerprint_ja4' => $this->tlsFingerprintJa4,
        ];
    }
}
