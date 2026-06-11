<?php

declare(strict_types=1);

namespace Supertab\Connect\Analytics;

/**
 * Sends analytics events to a destination.
 *
 * Implementations MUST be fail-open: emit() must never throw, block, or
 * otherwise alter request handling.
 */
interface AnalyticsTransportInterface
{
    /** Relay path that analytics events are POSTed to, appended to the base URL. */
    public const ANALYTICS_EVENTS_PATH = '/ingest/events';

    public function emit(AnalyticsEvent $event): void;
}
