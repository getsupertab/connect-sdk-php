<?php

declare(strict_types=1);

namespace Supertab\Connect\Analytics;

use Supertab\Connect\Analytics\Enum\FinalAction;
use Supertab\Connect\Analytics\Enum\TokenOutcome;
use Supertab\Connect\Enum\EnforcementMode;

/**
 * The verification/enforcement decision for a single request, captured at the
 * point handleRequest returns. Carries everything the analytics event needs to
 * describe what happened.
 */
final class Decision
{
    public function __construct(
        public readonly bool $hasToken,
        public readonly TokenOutcome $tokenOutcome,
        public readonly FinalAction $finalAction,
        public readonly EnforcementMode $enforcementMode,
    ) {}
}
