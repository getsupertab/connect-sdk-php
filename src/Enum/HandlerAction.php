<?php

declare(strict_types=1);

namespace Supertab\Connect\Enum;

enum HandlerAction: string
{
    case ALLOW = 'allow';
    case BLOCK = 'block';
}
