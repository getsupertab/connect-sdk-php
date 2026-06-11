<?php

declare(strict_types=1);

namespace Supertab\Connect\Analytics\Enum;

/**
 * The action the SDK took for a request, as reported in an analytics event.
 */
enum FinalAction: string
{
    case ALLOW = 'allow';
    case OBSERVE = 'observe';
    case BLOCK = 'block';
}
