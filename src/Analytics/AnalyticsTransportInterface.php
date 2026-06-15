<?php

declare(strict_types=1);

namespace Supertab\Connect\Analytics;

/**
 * Sends analytics events to a destination.
 *
 * Implementations MUST be fail-open: emit() never throws and never alters
 * request handling. Sends are synchronous, so implementations MUST keep the
 * added latency tightly bounded (short connect/read timeouts) — "fail-open"
 * promises bounded best-effort delivery, not zero blocking.
 */
interface AnalyticsTransportInterface
{
    /** Relay path that analytics events are POSTed to, appended to the base URL. */
    public const ANALYTICS_EVENTS_PATH = '/ingest/events';

    public function emit(AnalyticsEvent $event): void;
}
