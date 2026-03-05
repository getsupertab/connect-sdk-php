<?php

declare(strict_types=1);

namespace Supertab\Connect\Result;

use Supertab\Connect\Enum\HandlerAction;

abstract class HandlerResult
{
    /**
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public HandlerAction $action,
        public array $headers = [],
    ) {}
}
