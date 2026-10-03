<?php

declare(strict_types=1);

namespace Stewart\Testing\Websocket;

use Amp\Cancellation;
use Amp\DeferredFuture;
use Amp\Websocket\Client\WebsocketConnection;
use Amp\Websocket\Client\WebsocketConnector;
use Amp\Websocket\Client\WebsocketHandshake;
use LogicException;
use Stewart\Testing\Websocket\Collection\FakeWebsocketConnectionCollection;

final class FakeWebsocketConnector implements WebsocketConnector
{
    public private(set) FakeWebsocketConnectionCollection $connections;

    /** @var list<FakeWebsocketConnection> */
    private array $sockets;

    private bool $stalls = false;

    public function __construct(FakeWebsocketConnection ...$sockets)
    {
        $this->sockets = array_values($sockets);
        $this->connections = FakeWebsocketConnectionCollection::empty();
    }

    public static function createStalled(): self
    {
        $connector = new self();
        $connector->stalls = true;

        return $connector;
    }

    public static function createAuthenticatedConnection(string $haVersion = '2026.9.0'): FakeWebsocketConnection
    {
        $socket = new FakeWebsocketConnection();
        $socket->queueFrame(['type' => 'auth_required', 'ha_version' => $haVersion]);
        $socket->replyWhenSent('auth', ['type' => 'auth_ok', 'ha_version' => $haVersion]);

        return $socket;
    }

    public function connect(WebsocketHandshake $handshake, ?Cancellation $cancellation = null): WebsocketConnection
    {
        if ($this->stalls) {
            new DeferredFuture()->getFuture()->await($cancellation);
        }

        $socket = array_shift($this->sockets) ?? throw new LogicException('The fake connector ran out of sockets.');
        $this->connections = $this->connections->withConnection($socket);

        return $socket;
    }
}
