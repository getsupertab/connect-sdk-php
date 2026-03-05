<?php

declare(strict_types=1);

namespace Supertab\Connect\Result;

use Supertab\Connect\Enum\HandlerAction;

final class AllowResult extends HandlerResult
{
    /**
     * @param  array<string, string>  $headers
     */
    public function __construct(
        array $headers = [],
    ) {
        parent::__construct(HandlerAction::ALLOW, $headers);
    }
}
