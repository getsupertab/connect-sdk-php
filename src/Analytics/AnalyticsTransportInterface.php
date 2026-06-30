<?php

declare(strict_types=1);

namespace Supertab\Connect\Analytics;

/**
 * Sends analytics events to a destination.
 *
 * This interface is the public extension point for routing analytics through a
 * custom delivery path (a job queue, a platform HTTP client, a log sink, …);
 * an instance passed to {@see \Supertab\Connect\SupertabConnect} is used as-is.
 *
 * Implementations MUST be fail-open: emit() never throws and never alters
 * request handling. emit() MAY deliver synchronously or defer delivery (see
 * {@see DeferredAnalyticsTransport}); when it sends synchronously on the request
 * path it MUST keep the added latency tightly bounded (short connect/read
 * timeouts) — "fail-open" promises bounded best-effort delivery, not zero
 * blocking.
 */
interface AnalyticsTransportInterface
{
    /** Relay path that analytics events are POSTed to, appended to the base URL. */
    public const ANALYTICS_EVENTS_PATH = '/ingest/events';

    public function emit(AnalyticsEvent $event): void;
}
