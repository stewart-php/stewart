<?php

declare(strict_types=1);

namespace Stewart\Client\Tests\Fixtures;

use Amp\Http\Server\DefaultErrorHandler;
use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use Amp\Http\Server\SocketHttpServer;
use Amp\Socket\InternetAddress;
use Amp\Websocket\Server\Rfc6455Acceptor;
use Amp\Websocket\Server\Websocket;
use Amp\Websocket\Server\WebsocketClientHandler;
use Amp\Websocket\WebsocketClient;
use LogicException;
use Psr\Log\NullLogger;
use Stewart\Client\Connection\HomeAssistantUrl;
use Stewart\Support\Json\JsonDecoder;
use Stewart\Support\Json\JsonEncoder;

final class FakeHaServer implements WebsocketClientHandler
{
    public const string HA_VERSION = '2026.9.0';

    private const array SUBSCRIPTION_COMMAND_TYPES = ['subscribe_events' => true, 'subscribe_trigger' => true, 'unsubscribe_events' => true];

    /** @var array<string, array<string, mixed>> */
    private array $replies = [];

    /** @var array<string, true> */
    private array $heldTypes = [];

    /** @var array<string, list<array<array-key, mixed>>> */
    private array $receivedCommands = [];

    private ?string $tokenRejection = null;

    private bool $greetingWithheld = false;

    /** @var list<WebsocketClient> */
    private array $clients = [];

    private readonly SocketCapturingClientFactory $clientFactory;

    private function __construct(private readonly SocketHttpServer $server)
    {
        $this->clientFactory = new SocketCapturingClientFactory();
    }

    public static function start(): self
    {
        $logger = new NullLogger();
        $fake = new self(SocketHttpServer::createForDirectAccess($logger, enableCompression: false));

        $fake->server->expose('127.0.0.1:0');
        $fake->server->start(
            new Websocket($fake->server, $logger, new Rfc6455Acceptor(), $fake, clientFactory: $fake->clientFactory),
            new DefaultErrorHandler(),
        );

        return $fake;
    }

    public function getUrl(): HomeAssistantUrl
    {
        $address = $this->server->getServers()[0]->getAddress();

        if (!$address instanceof InternetAddress) {
            throw new LogicException('The fake Home Assistant listens on TCP only.');
        }

        return HomeAssistantUrl::parse('http://127.0.0.1:' . $address->getPort());
    }

    /** @param array<array-key, mixed> $result */
    public function answerCommand(string $type, array $result): void
    {
        $this->replies[$type] = ['type' => 'result', 'success' => true, 'result' => $result];
    }

    public function rejectCommand(string $type, string $code, string $message): void
    {
        $this->replies[$type] = ['type' => 'result', 'success' => false, 'error' => ['code' => $code, 'message' => $message]];
    }

    public function holdCommand(string $type): void
    {
        $this->heldTypes[$type] = true;
    }

    /** @return list<array<array-key, mixed>> */
    public function listReceivedCommands(string $type): array
    {
        return $this->receivedCommands[$type] ?? [];
    }

    public function rejectToken(string $message): void
    {
        $this->tokenRejection = $message;
    }

    public function withholdGreeting(): void
    {
        $this->greetingWithheld = true;
    }

    /** @param array<string, mixed> $event */
    public function pushEvent(int $subscriptionId, array $event): void
    {
        $this->sendText(JsonEncoder::encodeToJson(['id' => $subscriptionId, 'type' => 'event', 'event' => $event]));
    }

    public function sendText(string $payload): void
    {
        foreach ($this->clients as $client) {
            $client->sendText($payload);
        }
    }

    public function dropConnections(): void
    {
        $this->clients = [];
        $this->clientFactory->closeCapturedSockets();
    }

    public function closeConnections(int $code, string $reason): void
    {
        $clients = $this->clients;
        $this->clients = [];

        foreach ($clients as $client) {
            $client->close($code, $reason);
        }
    }

    public function stop(): void
    {
        $this->dropConnections();
        $this->server->stop();
    }

    public function handleClient(WebsocketClient $client, Request $request, Response $response): void
    {
        if ($this->greetingWithheld) {
            $client->receive();

            return;
        }

        $client->sendText(JsonEncoder::encodeToJson(['type' => 'auth_required', 'ha_version' => self::HA_VERSION]));
        $client->receive();

        if ($this->tokenRejection !== null) {
            $client->sendText(JsonEncoder::encodeToJson(['type' => 'auth_invalid', 'message' => $this->tokenRejection]));

            return;
        }

        $client->sendText(JsonEncoder::encodeToJson(['type' => 'auth_ok', 'ha_version' => self::HA_VERSION]));
        $this->clients[] = $client;

        while (($message = $client->receive()) !== null) {
            $this->answerReceivedCommand($client, $message->buffer());
        }
    }

    private function answerReceivedCommand(WebsocketClient $client, string $payload): void
    {
        $command = JsonDecoder::decodeJson($payload);
        $type = \is_array($command) && \is_string($command['type'] ?? null) ? $command['type'] : '';
        $id = \is_array($command) ? $command['id'] ?? null : null;

        if (\is_array($command)) {
            $this->receivedCommands[$type][] = $command;
        }

        if (isset($this->heldTypes[$type]) || !\is_int($id)) {
            return;
        }

        $reply = $this->replies[$type] ?? (isset(self::SUBSCRIPTION_COMMAND_TYPES[$type])
            ? ['type' => 'result', 'success' => true, 'result' => null]
            : ['type' => 'result', 'success' => false, 'error' => ['code' => 'unknown_command', 'message' => 'Unknown command.']]);

        $client->sendText(JsonEncoder::encodeToJson(['id' => $id, ...$reply]));
    }
}
