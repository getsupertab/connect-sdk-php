<?php

declare(strict_types=1);

namespace Supertab\Connect\Enum;

enum EnforcementMode: string
{
    case DISABLED = 'disabled';
    case SOFT = 'soft';
    case STRICT = 'strict';
}
