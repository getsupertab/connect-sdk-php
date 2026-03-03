<?php

declare(strict_types=1);

namespace Supertab\Connect\Result;

use Supertab\Connect\Enum\HandlerAction;

abstract class HandlerResult
{
    public function __construct(
        public HandlerAction $action,
    ) {}
}
