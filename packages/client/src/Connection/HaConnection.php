<?php

declare(strict_types=1);

namespace Stewart\Client\Connection;

use Amp\Cancellation;
use Amp\CancelledException;
use Amp\CompositeCancellation;
use Amp\DeferredCancellation;
use Amp\Websocket\Client\Rfc6455ConnectionFactory;
use Amp\Websocket\Client\Rfc6455Connector;
use Amp\Websocket\Client\WebsocketConnection;
use Amp\Websocket\Client\WebsocketConnector;
use Amp\Websocket\Client\WebsocketHandshake;
use Amp\Websocket\Parser\Rfc6455ParserFactory;
use Amp\Websocket\PeriodicHeartbeatQueue;
use Amp\Websocket\WebsocketCloseCode;
use Amp\Websocket\WebsocketCloseInfo;
use Closure;
use JsonException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Stewart\Client\Connection\Command\Authenticate;
use Stewart\Client\Connection\Command\HaCommand;
use Stewart\Client\Connection\Command\SubscriptionCommand;
use Stewart\Client\Connection\Command\UnsubscribeEvents;
use Stewart\Client\Exception\HaClientException;
use Stewart\Support\Json\JsonDecoder;
use Stewart\Support\Json\JsonEncoder;
use Stewart\Support\Json\JsonShape;
use Stewart\Support\Time\Deadlines;
use Throwable;

use function Amp\async;

/** @internal */
final class HaConnection
{
    private const string UNAUTHORIZED_ERROR_CODE = 'unauthorized';

    private ?WebsocketConnection $socket = null;

    private readonly MessageCorrelator $correlator;

    /** @var array<int, Closure(array<string, mixed>): void> */
    private array $eventHandlers = [];

    public private(set) ?string $haVersion = null;

    private ?DeferredCancellation $connecting = null;

    /** @var (Closure(HaClientException): void)|null */
    private ?Closure $onDisconnect = null;

    private EventDelivery $events;

    private ?WebsocketConnector $connector;

    public function __construct(
        private readonly ConnectionConfig $config,
        private readonly Deadlines $deadlines,
        private readonly LoggerInterface $logger = new NullLogger(),
        ?WebsocketConnector $connector = null,
    ) {
        $this->connector = $connector;
        $this->correlator = new MessageCorrelator();
        $this->events = new EventDelivery($config->eventBufferLimit, $logger);
    }

    public function connect(): void
    {
        if ($this->socket !== null) {
            $this->close();
        }

        $this->logger->debug('Connecting to Home Assistant', ['url' => (string) $this->config->url]);

        $this->connecting = $connecting = new DeferredCancellation();
        $cancellation = new CompositeCancellation(
            $connecting->getCancellation(),
            $this->deadlines->timeout($this->config->connectTimeout),
        );

        try {
            $socket = $this->openSocket($cancellation);

            try {
                $this->authenticate($socket, $cancellation);
            } catch (Throwable $e) {
                $socket->close();

                throw $e;
            }
        } catch (CancelledException $e) {
            throw $connecting->isCancelled()
                ? HaClientException::closedLocally('it was being established')
                : HaClientException::connectTimedOut((string) $this->config->url, $this->config->connectTimeout, $e);
        } finally {
            $this->connecting = null;
        }

        $this->socket = $socket;
        $this->events = new EventDelivery($this->config->eventBufferLimit, $this->logger);

        async(fn() => $this->receiveLoop($socket))->ignore();
    }

    public function flushEvents(): void
    {
        $this->events->flush();
    }

    /** @return array<array-key, mixed> */
    public function send(HaCommand $command): array
    {
        $response = $this->sendAndAwaitResponse($this->correlator->nextId(), $command);
        self::assertSuccess($response, $command);

        $result = $response['result'] ?? null;

        return \is_array($result)
            ? $result
            : throw HaClientException::protocolViolation(\sprintf('a non-object result for %s', $command->describe()));
    }

    /** @param Closure(array<string, mixed>): void $handler */
    public function subscribeEvents(SubscriptionCommand $command, Closure $handler): int
    {
        $id = $this->correlator->nextId();
        $this->eventHandlers[$id] = $handler;

        try {
            self::assertSuccess($this->sendAndAwaitResponse($id, $command), $command);
        } catch (Throwable $e) {
            unset($this->eventHandlers[$id]);

            throw $e;
        }

        $this->logger->debug('Subscribed to Home Assistant events', ['command' => $command->describe(), 'id' => $id]);

        return $id;
    }

    public function unsubscribeEvents(int $subscriptionId): void
    {
        unset($this->eventHandlers[$subscriptionId]);

        $command = new UnsubscribeEvents($subscriptionId);
        self::assertSuccess($this->sendAndAwaitResponse($this->correlator->nextId(), $command), $command);
    }

    /** @param Closure(HaClientException): void $handler */
    public function onDisconnect(Closure $handler): void
    {
        $this->onDisconnect = $handler;
    }

    public function isConnected(): bool
    {
        return $this->socket !== null;
    }

    public function close(): void
    {
        $this->connecting?->cancel();

        $socket = $this->socket;
        $this->socket = null;
        $this->eventHandlers = [];

        $this->events->close();
        $this->correlator->failAll(HaClientException::closedLocally('commands were waiting for an answer'));

        $socket?->close();
    }

    private function openSocket(Cancellation $cancellation): WebsocketConnection
    {
        $this->connector ??= $this->defaultConnector();

        try {
            return $this->connector->connect(new WebsocketHandshake($this->config->url->reveal()), $cancellation);
        } catch (CancelledException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw HaClientException::connectFailed((string) $this->config->url, $e);
        }
    }

    private function defaultConnector(): WebsocketConnector
    {
        $interval = $this->config->heartbeatInterval;

        return new Rfc6455Connector(
            connectionFactory: new Rfc6455ConnectionFactory(
                heartbeatQueue: $interval === null
                    ? null
                    : new PeriodicHeartbeatQueue(max(1, $this->config->heartbeatMissedLimit), max(1, (int) round($interval->toSeconds()))),
                parserFactory: new Rfc6455ParserFactory(
                    messageSizeLimit: $this->config->messageSizeLimit,
                    frameSizeLimit: $this->config->frameSizeLimit,
                ),
            ),
        );
    }

    /** @return array<string, mixed> */
    private function sendAndAwaitResponse(int $id, HaCommand $command): array
    {
        $socket = $this->socket ?? throw HaClientException::notConnected();
        $description = $command->describe();
        $payload = self::encodeMessage([...$command->toMessage(), 'id' => $id], $description);
        $future = $this->correlator->expect($id);

        try {
            try {
                $socket->sendText($payload);
            } catch (Throwable $e) {
                // A close or drop during the send already failed this command with the real cause.
                if ($future->isComplete()) {
                    $future->await();
                }

                throw HaClientException::sendFailed($description, $e);
            }

            return $future->await($this->deadlines->timeout($this->config->commandTimeout));
        } catch (CancelledException $e) {
            throw HaClientException::commandTimedOut($description, $this->config->commandTimeout, $e);
        } finally {
            $this->correlator->forget($id);
        }
    }

    private function authenticate(WebsocketConnection $socket, Cancellation $cancellation): void
    {
        $greeting = $this->receiveMessage($socket, $cancellation);

        if (($greeting['type'] ?? null) !== 'auth_required') {
            throw HaClientException::unexpectedGreeting(
                \is_scalar($greeting['type'] ?? null) ? (string) $greeting['type'] : 'nothing',
            );
        }

        $this->haVersion = \is_string($greeting['ha_version'] ?? null) ? $greeting['ha_version'] : 'unknown';

        $socket->sendText(self::encodeMessage(new Authenticate($this->config->token)->toMessage(), 'auth'));

        $response = $this->receiveMessage($socket, $cancellation);

        if (($response['type'] ?? null) !== 'auth_ok') {
            throw HaClientException::tokenRejected(
                \is_string($response['message'] ?? null) ? $response['message'] : 'no reason given',
            );
        }

        $this->haVersion = \is_string($response['ha_version'] ?? null) ? $response['ha_version'] : $this->haVersion;

        $this->logger->info('Authenticated with Home Assistant', [
            'url' => (string) $this->config->url,
            'ha_version' => $this->haVersion,
        ]);
    }

    /** @return array<string, mixed> */
    private function receiveMessage(WebsocketConnection $socket, Cancellation $cancellation): array
    {
        $message = $socket->receive($cancellation) ?? throw HaClientException::closedDuringAuthentication();

        return self::decodeMessage($message->buffer());
    }

    private function receiveLoop(WebsocketConnection $socket): void
    {
        try {
            while (($message = $socket->receive()) !== null) {
                $decoded = $this->decodeMessageOrLog($message->buffer());

                if ($decoded !== null) {
                    $this->dispatchMessage($decoded);
                }
            }

            $lost = self::convertCloseToLoss($socket->getCloseInfo());
        } catch (Throwable $e) {
            $lost = $e instanceof HaClientException ? $e : HaClientException::connectionDropped('socket failed: ' . $e->getMessage(), $e);
        }

        $this->handleConnectionLost($socket, $lost);
        $socket->close();
    }

    private function handleConnectionLost(WebsocketConnection $socket, HaClientException $lost): void
    {
        if ($this->socket !== $socket) {
            return;
        }

        $this->socket = null;
        $this->eventHandlers = [];
        $this->events->close();
        $abandoned = $this->correlator->failAll($lost);

        $this->logger->warning($lost->getMessage(), ['abandoned_commands' => $abandoned]);

        if ($this->onDisconnect === null) {
            return;
        }

        try {
            ($this->onDisconnect)($lost);
        } catch (Throwable $e) {
            $this->logger->error('Disconnect handler failed', ['exception' => $e]);
        }
    }

    /** @return array<string, mixed>|null */
    private function decodeMessageOrLog(string $payload): ?array
    {
        try {
            return self::decodeMessage($payload);
        } catch (HaClientException $e) {
            $this->logger->error('Failed handling a Home Assistant message', ['exception' => $e]);

            return null;
        }
    }

    /** @param array<string, mixed> $message */
    private function dispatchMessage(array $message): void
    {
        $id = $message['id'] ?? null;

        if (!\is_int($id)) {
            return;
        }

        match ($message['type'] ?? null) {
            'result' => $this->correlator->resolve($id, $message),
            'event' => $this->dispatchEvent($id, $message),
            default => null,
        };
    }

    /** @param array<string, mixed> $message */
    private function dispatchEvent(int $id, array $message): void
    {
        $handler = $this->eventHandlers[$id] ?? null;
        $event = $message['event'] ?? null;

        if ($handler === null || !\is_array($event)) {
            return;
        }

        if ($this->events->enqueue($handler, JsonShape::treatKeysAsStrings($event)) === EnqueueOutcome::Full) {
            throw HaClientException::eventBacklogExceeded($this->config->eventBufferLimit);
        }
    }

    // amphp reports its own parser's size check as a close by the peer, so the code decides that case.
    private static function convertCloseToLoss(WebsocketCloseInfo $close): HaClientException
    {
        $reason = $close->getReason();

        return match (true) {
            $close->getCode() === WebsocketCloseCode::MESSAGE_TOO_LARGE => HaClientException::messageTooLarge(),
            $close->isByPeer() => HaClientException::connectionDropped($reason === '' ? 'closed by Home Assistant' : 'closed by Home Assistant: ' . $reason),
            default => HaClientException::connectionDropped(ltrim(\sprintf('%s (close code %d)', $reason, $close->getCode()))),
        };
    }

    /** @param array<string, mixed> $response */
    private static function assertSuccess(array $response, HaCommand $command): void
    {
        if (($response['success'] ?? false) === true) {
            return;
        }

        $error = \is_array($response['error'] ?? null) ? $response['error'] : [];
        $message = \is_string($error['message'] ?? null) ? $error['message'] : 'unknown error';
        $code = \is_scalar($error['code'] ?? null) ? (string) $error['code'] : null;

        throw $code === self::UNAUTHORIZED_ERROR_CODE
            ? HaClientException::commandUnauthorized($command->describe(), $message, $code)
            : HaClientException::commandRejected($command->describe(), $message, $code);
    }

    /** @param array<string, mixed> $message */
    private static function encodeMessage(array $message, string $description): string
    {
        try {
            return JsonEncoder::encodeToJson($message);
        } catch (JsonException $e) {
            throw HaClientException::commandUnencodable($description, $e);
        }
    }

    /** @return array<string, mixed> */
    private static function decodeMessage(string $payload): array
    {
        try {
            $decoded = JsonDecoder::decodeJson($payload);
        } catch (JsonException $e) {
            throw HaClientException::protocolViolation('a message that is not JSON', $e);
        }

        if (!\is_array($decoded)) {
            throw HaClientException::protocolViolation('a non-object message');
        }

        return JsonShape::treatKeysAsStrings($decoded);
    }
}
