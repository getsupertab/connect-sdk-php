<?php

declare(strict_types=1);

namespace Supertab\Connect\Enum;

enum LicenseTokenInvalidReason: string
{
    case MISSING_TOKEN = 'missing_license_token';
    case INVALID_HEADER = 'invalid_license_header';
    case INVALID_ALG = 'invalid_license_algorithm';
    case INVALID_PAYLOAD = 'invalid_license_payload';
    case INVALID_ISSUER = 'invalid_license_issuer';
    case SIGNATURE_VERIFICATION_FAILED = 'license_signature_verification_failed';
    case EXPIRED = 'license_token_expired';
    case INVALID_AUDIENCE = 'invalid_license_audience';
    case SERVER_ERROR = 'server_error';

    public function toErrorDescription(): string
    {
        return match ($this) {
            self::MISSING_TOKEN => 'Authorization header missing or malformed',
            self::INVALID_ALG => 'Unsupported token algorithm',
            self::EXPIRED => 'The license token has expired',
            self::SIGNATURE_VERIFICATION_FAILED => 'The license token signature is invalid',
            self::INVALID_HEADER => 'The license token header is malformed',
            self::INVALID_PAYLOAD => 'The license token payload is malformed',
            self::INVALID_ISSUER => 'The license token issuer is not recognized',
            self::INVALID_AUDIENCE => 'The license does not grant access to this resource',
            self::SERVER_ERROR => 'The server encountered an error validating the license',
        };
    }
}
