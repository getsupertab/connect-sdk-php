<?php

declare(strict_types=1);

namespace Supertab\Connect\Analytics;

/**
 * Opens (or returns a pooled) stream to the analytics relay.
 *
 * The concrete implementation uses a persistent socket so the connection is
 * reused across requests within the same worker/process.
 */
interface PersistentStreamFactoryInterface
{
    /**
     * @return resource a connected stream
     *
     * @throws \RuntimeException when a connection cannot be established
     */
    public function open();
}
