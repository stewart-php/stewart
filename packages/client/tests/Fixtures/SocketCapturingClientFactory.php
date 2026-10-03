<?php

declare(strict_types=1);

namespace Stewart\Client\Tests\Fixtures;

use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use Amp\Socket\Socket;
use Amp\Websocket\Compression\WebsocketCompressionContext;
use Amp\Websocket\Server\Rfc6455ClientFactory;
use Amp\Websocket\Server\WebsocketClientFactory;
use Amp\Websocket\WebsocketClient;

final class SocketCapturingClientFactory implements WebsocketClientFactory
{
    /** @var list<Socket> */
    private array $sockets = [];

    private readonly Rfc6455ClientFactory $clients;

    public function __construct()
    {
        $this->clients = new Rfc6455ClientFactory(heartbeatQueue: null, rateLimit: null);
    }

    public function createClient(Request $request, Response $response, Socket $socket, ?WebsocketCompressionContext $compressionContext): WebsocketClient
    {
        $this->sockets[] = $socket;

        return $this->clients->createClient($request, $response, $socket, $compressionContext);
    }

    public function closeCapturedSockets(): void
    {
        $sockets = $this->sockets;
        $this->sockets = [];

        foreach ($sockets as $socket) {
            $socket->close();
        }
    }
}
