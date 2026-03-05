<?php

declare(strict_types=1);

namespace Supertab\Connect\Customer;

final class ContentBlock
{
    public function __construct(
        public readonly string $urlPattern,
        public readonly string $server,
        public readonly string $licenseXml,
    ) {}
}
