<?php

declare(strict_types=1);

namespace Supertab\Connect\Enum;

enum EnforcementMode: string
{
    case DISABLED = 'disabled';
    case OBSERVE = 'observe';
    case ENFORCE = 'enforce';
}
