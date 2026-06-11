<?php

declare(strict_types=1);

namespace Supertab\Connect\Analytics;

use DateTimeImmutable;
use DateTimeZone;
use Supertab\Connect\Enum\EnforcementMode;
use Supertab\Connect\Http\RequestContext;

/**
 * Builds an {@see AnalyticsEvent} from a request context and a {@see Decision}.
 *
 * Port of the TypeScript SDK's buildAnalyticsEvent(). The PHP SDK runs at the
 * origin, so classification signals come from $_SERVER (via
 * {@see RequestContext::fromGlobals()}) or are injected explicitly on the
 * context — never auto-derived from CDN-specific headers.
 */
final class AnalyticsEventFactory
{
    /**
     * The PHP SDK runs on the origin server, not inside a CDN, so it reports a
     * fixed source rather than a CDN brand. Overridable via the constructor.
     */
    public const DEFAULT_SOURCE_CDN = 'origin';

    public function __construct(
        private readonly string $sourceCdn = self::DEFAULT_SOURCE_CDN,
    ) {}

    public function build(RequestContext $context, Decision $decision, ?DateTimeImmutable $now = null): AnalyticsEvent
    {
        $timestamp = ($now ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('UTC'));

        return new AnalyticsEvent(
            timestamp: $timestamp->format('Y-m-d\TH:i:s.v\Z'),
            requestId: $this->resolveRequestId($context),
            sourceCdn: $this->sourceCdn,
            userAgent: $context->userAgent ?? '',
            clientIp: ClientIpNormalizer::normalize($context->clientIp),
            path: $this->safePath($context->url),
            method: $context->method ?? '',
            referer: $context->headers['referer'] ?? '',
            acceptLanguage: $context->acceptLanguage ?? '',
            requestCountry: $context->requestCountry,
            requestAsn: $context->requestAsn,
            tlsFingerprint: $context->tlsFingerprint,
            hasToken: $decision->hasToken,
            tokenOutcome: $decision->tokenOutcome,
            finalAction: $decision->finalAction,
            enforcementMode: $this->enforcementModeToWire($decision->enforcementMode),
            signatureAgent: $context->headers['signature-agent'] ?? null,
            signatureInput: $context->headers['signature-input'] ?? null,
            signature: $context->headers['signature'] ?? null,
        );
    }

    private function resolveRequestId(RequestContext $context): string
    {
        if ($context->requestId !== null && $context->requestId !== '') {
            return $context->requestId;
        }

        $headerId = $context->headers['x-request-id'] ?? null;
        if (is_string($headerId) && $headerId !== '') {
            return $headerId;
        }

        return self::generateUuidV4();
    }

    private function safePath(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);

        return is_string($path) ? $path : '';
    }

    private function enforcementModeToWire(EnforcementMode $mode): string
    {
        return match ($mode) {
            EnforcementMode::DISABLED => 'disabled',
            EnforcementMode::SOFT => 'observe',
            EnforcementMode::STRICT => 'enforce',
        };
    }

    private static function generateUuidV4(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
