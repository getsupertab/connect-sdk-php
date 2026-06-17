<?php

declare(strict_types=1);

namespace Supertab\Connect\Analytics;

/**
 * A {@see KeepAliveConnectionInterface} that prefers a primary connection and
 * falls back to a secondary one if the primary fails.
 *
 * Used to make the persistent-socket transport safe everywhere: try the
 * persistent connection, and if it throws (unsupported platform, blocked
 * functions, protocol error, …) latch to the proven cURL connection for the
 * rest of the process. The result is never worse than the fallback alone.
 */
final class FallbackConnection implements KeepAliveConnectionInterface
{
    private bool $primaryDisabled = false;

    public function __construct(
        private readonly KeepAliveConnectionInterface $primary,
        private readonly KeepAliveConnectionInterface $fallback,
    ) {}

    public function post(string $body, array $headers): int
    {
        if (! $this->primaryDisabled) {
            try {
                return $this->primary->post($body, $headers);
            } catch (\Throwable) {
                // The primary path is broken in this environment; stop trying it
                // and use the proven fallback from here on.
                $this->primaryDisabled = true;
            }
        }

        return $this->fallback->post($body, $headers);
    }
}
