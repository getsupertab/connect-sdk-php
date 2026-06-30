<?php

declare(strict_types=1);

namespace Supertab\Connect\Analytics;

/**
 * Decorator that takes the analytics POST off the user-perceived latency path
 * on FastCGI-style SAPIs (PHP-FPM, LiteSpeed, FrankenPHP).
 *
 * When `fastcgi_finish_request()` is available, events are buffered during the
 * request and flushed at shutdown — after the response has been sent to the
 * client and the connection closed — so the visitor never waits on the POST.
 * The worker stays occupied for the duration of the flush, so the wrapped
 * transport must still bound its own latency.
 *
 * When the function is unavailable (mod_php/apache2handler, the built-in CLI
 * server, worker runtimes), emission falls back to fully synchronous delivery
 * through the wrapped transport — identical to not using this decorator.
 *
 * Fail-open like every transport: emit()/flush() never throw.
 */
final class DeferredAnalyticsTransport implements AnalyticsTransportInterface
{
    /** @var list<AnalyticsEvent> */
    private array $buffer = [];

    private bool $flushScheduled = false;

    private readonly bool $deferralAvailable;

    /** @var \Closure(callable():void):void */
    private readonly \Closure $scheduleFlush;

    /**
     * @param  bool|null  $deferralAvailable  Whether deferral is possible; defaults to detecting
     *                                         `fastcgi_finish_request()`. (Injectable for tests.)
     * @param  (\Closure(callable():void):void)|null  $scheduleFlush  Arranges for the flush callback to
     *                                         run after the response is finished; defaults to a shutdown
     *                                         handler that calls `fastcgi_finish_request()` then flushes.
     *                                         (Injectable for tests.)
     */
    public function __construct(
        private readonly AnalyticsTransportInterface $inner,
        private readonly bool $debug = false,
        ?bool $deferralAvailable = null,
        ?\Closure $scheduleFlush = null,
    ) {
        $this->deferralAvailable = $deferralAvailable ?? \function_exists('fastcgi_finish_request');

        $this->scheduleFlush = $scheduleFlush ?? static function (callable $flush): void {
            \register_shutdown_function(static function () use ($flush): void {
                if (\function_exists('fastcgi_finish_request')) {
                    \fastcgi_finish_request();
                }
                $flush();
            });
        };
    }

    public function emit(AnalyticsEvent $event): void
    {
        if (! $this->deferralAvailable) {
            $this->safeEmit($event);

            return;
        }

        $this->buffer[] = $event;

        if (! $this->flushScheduled) {
            $this->flushScheduled = true;
            ($this->scheduleFlush)(fn () => $this->flush());
        }
    }

    /**
     * Deliver every buffered event through the wrapped transport. Safe to call
     * more than once: the buffer is drained, so repeat calls are no-ops.
     */
    public function flush(): void
    {
        if ($this->buffer === []) {
            return;
        }

        $events = $this->buffer;
        $this->buffer = [];

        foreach ($events as $event) {
            $this->safeEmit($event);
        }
    }

    private function safeEmit(AnalyticsEvent $event): void
    {
        try {
            $this->inner->emit($event);
        } catch (\Throwable $e) {
            if ($this->debug) {
                \error_log('[SupertabConnect] Deferred analytics emit failed: ' . $e->getMessage());
            }
        }
    }
}
