<?php

declare(strict_types=1);

namespace Supertab\Connect\Tests\Analytics;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Supertab\Connect\Analytics\PersistentSocketConnection;
use Supertab\Connect\Analytics\PersistentStreamFactoryInterface;

final class PersistentSocketConnectionTest extends TestCase
{
    /** @var list<resource> server ends kept open for the lifetime of a test */
    private array $keepAlive = [];

    /**
     * @return array{0: resource, 1: resource} [client end (for the connection), server end (for the test)]
     */
    private function pair(string $serverPrewrites = ''): array
    {
        [$client, $server] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        if ($serverPrewrites !== '') {
            fwrite($server, $serverPrewrites);
        }
        // Retain the server end so its peer stays open even if the test only
        // keeps the client (otherwise GC closes it and writes break).
        $this->keepAlive[] = $server;

        return [$client, $server];
    }

    /**
     * @return resource a client stream whose peer is already closed
     */
    private function deadClient()
    {
        [$client, $server] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        fclose($server);

        return $client;
    }

    /**
     * @param  list<resource>  $streams
     */
    private function factory(array $streams): PersistentStreamFactoryInterface
    {
        return new class ($streams) implements PersistentStreamFactoryInterface {
            public int $opens = 0;

            /** @param list<resource> $streams */
            public function __construct(public array $streams)
            {}

            public function open()
            {
                $this->opens++;
                if ($this->streams === []) {
                    throw new RuntimeException('no more streams');
                }

                return array_shift($this->streams);
            }
        };
    }

    private function readAvailable($stream): string
    {
        stream_set_blocking($stream, false);

        return (string) stream_get_contents($stream);
    }

    public function test_sends_well_formed_request_and_returns_status(): void
    {
        [$client, $server] = $this->pair("HTTP/1.1 202 Accepted\r\nContent-Length: 0\r\n\r\n");
        $connection = new PersistentSocketConnection($this->factory([$client]), 'relay.example', '/ingest/events');

        $status = $connection->post('{"a":1}', [
            'Authorization' => 'Bearer key',
            'Content-Type' => 'application/json',
        ]);

        $this->assertSame(202, $status);

        $request = $this->readAvailable($server);
        $this->assertStringContainsString("POST /ingest/events HTTP/1.1\r\n", $request);
        $this->assertStringContainsString("Host: relay.example\r\n", $request);
        $this->assertStringContainsString("Authorization: Bearer key\r\n", $request);
        $this->assertStringContainsString("Content-Type: application/json\r\n", $request);
        $this->assertStringContainsString("Content-Length: 7\r\n", $request);
        $this->assertStringContainsString("Connection: keep-alive\r\n", $request);
        $this->assertStringEndsWith("\r\n\r\n{\"a\":1}", $request);
    }

    public function test_reuses_connection_across_emits_with_content_length(): void
    {
        $response = "HTTP/1.1 202 Accepted\r\nContent-Length: 2\r\n\r\nOK";
        [$client] = $this->pair($response . $response);
        $factory = $this->factory([$client]);
        $connection = new PersistentSocketConnection($factory, 'relay.example', '/ingest/events');

        $this->assertSame(202, $connection->post('{}', []));
        $this->assertSame(202, $connection->post('{}', []));
        $this->assertSame(1, $factory->opens); // one socket served both
    }

    public function test_drains_chunked_response_and_reuses(): void
    {
        $response = "HTTP/1.1 202 Accepted\r\nTransfer-Encoding: chunked\r\n\r\n2\r\nOK\r\n0\r\n\r\n";
        [$client] = $this->pair($response . $response);
        $factory = $this->factory([$client]);
        $connection = new PersistentSocketConnection($factory, 'relay.example', '/ingest/events');

        $this->assertSame(202, $connection->post('{}', []));
        $this->assertSame(202, $connection->post('{}', []));
        $this->assertSame(1, $factory->opens);
    }

    public function test_connection_close_header_forces_reconnect(): void
    {
        [$client1] = $this->pair("HTTP/1.1 202 Accepted\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");
        [$client2] = $this->pair("HTTP/1.1 202 Accepted\r\nContent-Length: 0\r\n\r\n");
        $factory = $this->factory([$client1, $client2]);
        $connection = new PersistentSocketConnection($factory, 'relay.example', '/ingest/events');

        $this->assertSame(202, $connection->post('{}', []));
        $this->assertSame(202, $connection->post('{}', []));
        $this->assertSame(2, $factory->opens); // closed after first, reconnected for second
    }

    public function test_reconnects_once_on_stale_connection(): void
    {
        [$good] = $this->pair("HTTP/1.1 202 Accepted\r\nContent-Length: 0\r\n\r\n");
        $factory = $this->factory([$this->deadClient(), $good]);
        $connection = new PersistentSocketConnection($factory, 'relay.example', '/ingest/events');

        $this->assertSame(202, $connection->post('{}', []));
        $this->assertSame(2, $factory->opens); // first (stale) failed, reconnected once
    }

    public function test_throws_when_reconnect_also_fails(): void
    {
        $factory = $this->factory([$this->deadClient(), $this->deadClient()]);
        $connection = new PersistentSocketConnection($factory, 'relay.example', '/ingest/events');

        $this->expectException(RuntimeException::class);
        $connection->post('{}', []);
    }
}
