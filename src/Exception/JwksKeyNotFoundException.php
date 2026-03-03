<?php

declare(strict_types=1);

namespace Supertab\Connect\Exception;

final class JwksKeyNotFoundException extends SupertabConnectException
{
    public function __construct(?string $kid)
    {
        parent::__construct("No matching platform key found: {$kid}");
    }
}
