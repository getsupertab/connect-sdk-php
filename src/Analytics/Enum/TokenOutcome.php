<?php

declare(strict_types=1);

namespace Supertab\Connect\Analytics\Enum;

/**
 * Analytics wire vocabulary for the outcome of license-token handling.
 *
 * Distinct from {@see \Supertab\Connect\Enum\LicenseTokenInvalidReason}, which
 * describes why verification failed. This enum is the value sent in the
 * `token_outcome` field of an analytics event.
 */
enum TokenOutcome: string
{
    case ABSENT = 'absent';
    case VALID = 'valid';
    case EXPIRED = 'expired';
    case INVALID_SIGNATURE = 'invalid_signature';
    case INVALID_AUDIENCE = 'invalid_audience';
    case INVALID_RESOURCE = 'invalid_resource';
    case INVALID_ISSUER = 'invalid_issuer';
    case MALFORMED = 'malformed';
    case SERVER_ERROR = 'server_error';
    case NOT_VALIDATED = 'not_validated';
}
