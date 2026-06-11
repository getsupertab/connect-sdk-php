<?php

declare(strict_types=1);

namespace Supertab\Connect\Tests\Analytics;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Supertab\Connect\Analytics\FallbackConnection;
use Supertab\Connect\Analytics\KeepAliveConnectionInterface;

final class FallbackConnectionTest extends TestCase
{
    public function test_uses_primary_when_it_succeeds(): void
    {
        $primary = $this->createMock(KeepAliveConnectionInterface::class);
        $primary->expects($this->once())->method('post')->willReturn(202);

        $fallback = $this->createMock(KeepAliveConnectionInterface::class);
        $fallback->expects($this->never())->method('post');

        $connection = new FallbackConnection($primary, $fallback);

        $this->assertSame(202, $connection->post('{}', []));
    }

    public function test_falls_back_when_primary_throws(): void
    {
        $primary = $this->createMock(KeepAliveConnectionInterface::class);
        $primary->method('post')->willThrowException(new RuntimeException('persistent failed'));

        $fallback = $this->createMock(KeepAliveConnectionInterface::class);
        $fallback->expects($this->once())->method('post')->willReturn(200);

        $connection = new FallbackConnection($primary, $fallback);

        $this->assertSame(200, $connection->post('{}', []));
    }

    public function test_latches_to_fallback_after_primary_fails_once(): void
    {
        // Primary is tried exactly once; after it fails the connection stops
        // retrying it and goes straight to the fallback on later calls.
        $primary = $this->createMock(KeepAliveConnectionInterface::class);
        $primary->expects($this->once())->method('post')->willThrowException(new RuntimeException('boom'));

        $fallback = $this->createMock(KeepAliveConnectionInterface::class);
        $fallback->expects($this->exactly(3))->method('post')->willReturn(202);

        $connection = new FallbackConnection($primary, $fallback);

        $connection->post('{}', []);
        $connection->post('{}', []);
        $connection->post('{}', []);
    }
}
