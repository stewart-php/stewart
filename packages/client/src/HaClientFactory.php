<?php

declare(strict_types=1);

namespace Stewart\Client;

use Amp\Websocket\Client\WebsocketConnector;
use Psr\Log\LoggerInterface;
use Stewart\Client\Connection\ConnectionConfig;
use Stewart\Support\Time\Deadlines;

final readonly class HaClientFactory
{
    public function __construct(
        private Deadlines $deadlines,
        private ?WebsocketConnector $haWebsocketConnector = null,
    ) {}

    public function createClient(ConnectionConfig $config, LoggerInterface $logger): HaClient
    {
        return HaClient::fromConnectionConfig($config, $this->deadlines, $logger, $this->haWebsocketConnector);
    }
}
