<?php

declare(strict_types=1);

namespace Supertab\Connect\Analytics;

/**
 * Analytics transport that hands each event to a caller-supplied callback —
 * the ergonomic form of the {@see AnalyticsTransportInterface} extension point.
 *
 * Lets an integrator route analytics through their own delivery path without
 * writing a class. The canonical use is offloading to a job queue: e.g. a
 * WordPress plugin enqueues the serialized event onto Action Scheduler and
 * POSTs it from the scheduled job, fully off the visitor's request:
 *
 *     new CallbackAnalyticsTransport(
 *         fn (AnalyticsEvent $e) => as_enqueue_async_action('st_emit', [$e->toArray()])
 *     );
 *
 * Fail-open: a throwing callback is swallowed (logged only in debug mode), so
 * emission never affects request handling.
 */
final class CallbackAnalyticsTransport implements AnalyticsTransportInterface
{
    /** @var \Closure(AnalyticsEvent):void */
    private readonly \Closure $callback;

    /**
     * @param  callable(AnalyticsEvent):void  $callback
     */
    public function __construct(
        callable $callback,
        private readonly bool $debug = false,
    ) {
        $this->callback = $callback(...);
    }

    public function emit(AnalyticsEvent $event): void
    {
        try {
            ($this->callback)($event);
        } catch (\Throwable $e) {
            if ($this->debug) {
                \error_log('[SupertabConnect] Analytics callback failed: ' . $e->getMessage());
            }
        }
    }
}
