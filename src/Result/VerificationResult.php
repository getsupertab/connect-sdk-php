<?php

declare(strict_types=1);

namespace Supertab\Connect\Result;

final class VerificationResult
{
    public function __construct(
        public bool $valid,
        public ?string $error = null,
    ) {}
}
