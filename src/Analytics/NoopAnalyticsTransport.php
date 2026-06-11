<?php

declare(strict_types=1);

namespace Supertab\Connect\Analytics;

/**
 * No-op analytics transport used when analytics is disabled.
 */
final class NoopAnalyticsTransport implements AnalyticsTransportInterface
{
    public function emit(AnalyticsEvent $event): void
    {
        // intentional no-op
    }
}
