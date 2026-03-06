<?php

declare(strict_types=1);

namespace Supertab\Connect\Bot;

use Supertab\Connect\Http\RequestContext;

interface BotDetectorInterface
{
    /**
     * Determine if the request appears to be from a bot.
     */
    public function isBot(RequestContext $context): bool;
}
