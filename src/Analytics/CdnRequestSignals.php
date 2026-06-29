<?php

declare(strict_types=1);

namespace Supertab\Connect\Analytics;

/**
 * CDN-supplied request signals that cannot be read from the request at a PHP
 * origin — TLS/transport fingerprints, verified-bot category, AS organization,
 * etc. The edge SDKs (TypeScript) derive these from Cloudflare's `request.cf`
 * or Fastly headers; the PHP SDK runs at the origin, so a CDN-fronted caller
 * injects them explicitly on {@see \Supertab\Connect\Http\RequestContext}.
 * Anything left null is emitted as null on the analytics event.
 *
 * Field names mirror the TypeScript SDK's `CdnRequestSignals` contract so the
 * data maps 1:1 onto the wire (snake_case) event fields.
 */
final class CdnRequestSignals
{
    public function __construct(
        public readonly ?string $acceptEncoding = null,
        public readonly ?string $httpProtocol = null,
        public readonly ?string $tlsVersion = null,
        public readonly ?string $tlsCipher = null,
        public readonly ?int $tlsClientHelloLength = null,
        public readonly ?string $tlsClientExtensionsSha1 = null,
        public readonly ?string $asOrganization = null,
        public readonly ?int $clientTcpRtt = null,
        public readonly ?string $cdnVerifiedBotCategory = null,
        public readonly ?string $requestPriority = null,
        public readonly ?string $tlsFingerprintJa4 = null,
    ) {}
}
