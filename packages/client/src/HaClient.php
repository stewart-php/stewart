<?php

declare(strict_types=1);

namespace Stewart\Client;

use Amp\Websocket\Client\WebsocketConnector;
use Closure;
use DateTimeZone;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Stewart\Client\Connection\Command\CallService;
use Stewart\Client\Connection\Command\GetConfig;
use Stewart\Client\Connection\Command\GetCurrentUser;
use Stewart\Client\Connection\Command\GetEntityRegistry;
use Stewart\Client\Connection\Command\GetHistoryDuringPeriod;
use Stewart\Client\Connection\Command\GetServices;
use Stewart\Client\Connection\Command\GetStates;
use Stewart\Client\Connection\Command\SubscribeEvents;
use Stewart\Client\Connection\Command\SubscribeTrigger;
use Stewart\Client\Connection\Command\SubscriptionCommand;
use Stewart\Client\Connection\ConnectionConfig;
use Stewart\Client\Connection\HaConnection;
use Stewart\Client\Event\EventDecoder;
use Stewart\Client\Exception\HaClientError;
use Stewart\Client\Exception\HaClientException;
use Stewart\Client\Registry\Collection\EntityRegistryCollection;
use Stewart\Client\Registry\EntityRegistryEntry;
use Stewart\Client\State\EntityStateDecoder;
use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\Event\HaEvent;
use Stewart\Contracts\Exception\HistoryException;
use Stewart\Contracts\Exception\IdentifierException;
use Stewart\Contracts\Exception\ServiceCallException;
use Stewart\Contracts\History\Collection\HistoricalStateCollection;
use Stewart\Contracts\History\EntityStateHistory;
use Stewart\Contracts\History\HistoryDetail;
use Stewart\Contracts\History\HistoryWindow;
use Stewart\Contracts\Service\ServiceResponse;
use Stewart\Contracts\Service\ServiceTarget;
use Stewart\Contracts\State\Collection\EntityStateCollection;
use Stewart\Contracts\State\EventContext;
use Stewart\Contracts\State\StateChange;
use Stewart\Contracts\Trigger\Collection\HaTriggerCollection;
use Stewart\Contracts\Trigger\TriggerEvent;
use Stewart\Support\Json\JsonShape;
use Stewart\Support\Time\Deadlines;

final class HaClient
{
    private const string UNKNOWN_COMMAND_CODE = 'unknown_command';

    public function __construct(
        private readonly HaConnection $connection,
        private readonly EventDecoder $decoder,
        private readonly EntityStateDecoder $states,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    public static function fromConnectionConfig(
        ConnectionConfig $config,
        Deadlines $deadlines,
        LoggerInterface $logger = new NullLogger(),
        ?WebsocketConnector $connector = null,
    ): self {
        $states = new EntityStateDecoder($logger);

        return new self(new HaConnection($config, $deadlines, $logger, $connector), new EventDecoder($states), $states, $logger);
    }

    public function connect(): void
    {
        $this->connection->connect();
    }

    public function close(): void
    {
        $this->connection->close();
    }

    public function isConnected(): bool
    {
        return $this->connection->isConnected();
    }

    /** @param Closure(HaClientException): void $handler */
    public function onDisconnect(Closure $handler): void
    {
        $this->connection->onDisconnect($handler);
    }

    public function getHaVersion(): ?string
    {
        return $this->connection->haVersion;
    }

    public function flushEvents(): void
    {
        $this->connection->flushEvents();
    }

    public function getStates(): EntityStateCollection
    {
        $states = [];

        foreach ($this->connection->send(new GetStates()) as $entry) {
            $state = \is_array($entry) ? $this->states->decodeEntityStateOrSkip($entry) : null;

            if ($state !== null) {
                $states[] = $state;
            }
        }

        return EntityStateCollection::keyedByEntityId($states);
    }

    public function getEntityRegistry(): EntityRegistryCollection
    {
        $entries = [];

        foreach ($this->connection->send(new GetEntityRegistry()) as $entry) {
            if (!\is_array($entry)) {
                continue;
            }

            try {
                $entries[] = EntityRegistryEntry::fromArray($entry);
            } catch (IdentifierException $e) {
                $this->logger->debug('Skipped an entity registry entry without a valid entity id', ['exception' => $e]);
            }
        }

        return EntityRegistryCollection::keyedByEntityId($entries);
    }

    /** @throws HaClientException */
    public function getCurrentUserId(): string
    {
        $id = $this->connection->send(new GetCurrentUser())['id'] ?? null;

        return \is_string($id) && $id !== '' ? $id : throw HaClientException::protocolViolation('a current user without an id');
    }

    public function getConfig(): HaConfig
    {
        return HaConfig::fromGetConfigResult($this->connection->send(new GetConfig()));
    }

    public function getTimeZone(): DateTimeZone
    {
        return $this->getSiteSettings()->timeZone;
    }

    public function getSiteSettings(): HaSiteSettings
    {
        $config = $this->getConfig();

        if ($config->timeZone === null && $config->timeZoneName !== null) {
            $this->logger->warning('Home Assistant reported an unusable timezone; falling back to UTC.', [
                'time_zone' => $config->timeZoneName,
            ]);
        }

        return new HaSiteSettings($config->timeZone ?? new DateTimeZone('UTC'), $config->location);
    }

    /** @return array<string, mixed> */
    public function getServices(): array
    {
        return JsonShape::treatKeysAsStrings($this->connection->send(new GetServices()));
    }

    /**
     * @param array<string, mixed> $data
     * @throws ServiceCallException
     */
    public function callService(
        string $domain,
        string $service,
        array $data = [],
        ?ServiceTarget $target = null,
        bool $returnResponse = false,
    ): ServiceResponse {
        try {
            $result = $this->connection->send(new CallService($domain, $service, $data, $target, $returnResponse));
        } catch (HaClientException $e) {
            throw $this->toServiceCallException($domain, $service, $e);
        }

        $response = $result['response'] ?? null;
        $context = $result['context'] ?? null;

        return new ServiceResponse(
            $domain,
            $service,
            \is_array($response) ? JsonShape::treatKeysAsStrings($response) : [],
            \is_array($context) ? EventContext::fromArray($context) : null,
        );
    }

    /** @throws HistoryException */
    public function fetchHistory(EntityId $entityId, HistoryWindow $window, HistoryDetail $detail): EntityStateHistory
    {
        try {
            $result = $this->connection->send(new GetHistoryDuringPeriod($entityId, $window, $detail));
        } catch (HaClientException $e) {
            throw $this->toHistoryException($entityId, $e);
        }

        $rows = $result[$entityId->value] ?? [];
        $states = [];

        foreach (\is_array($rows) ? $rows : [] as $row) {
            $state = \is_array($row) ? $this->states->decodeCompressedHistoryRowOrSkip($entityId, $row) : null;

            if ($state !== null) {
                $states[] = $state;
            }
        }

        return new EntityStateHistory($entityId, $window, HistoricalStateCollection::fromStates($states));
    }

    /**
     * @param Closure(StateChange): void $onStateChange
     * @param Closure(HaEvent): void $onEvent
     * @throws HaClientException
     */
    public function subscribeAllEvents(Closure $onStateChange, Closure $onEvent): int
    {
        $decoder = $this->decoder;

        return $this->subscribe(new SubscribeEvents(), static function (array $event) use ($decoder, $onStateChange, $onEvent): void {
            if (($event['event_type'] ?? null) === HaEvent::STATE_CHANGED) {
                $change = $decoder->decodeStateChange($event);

                if ($change !== null) {
                    $onStateChange($change);
                }

                return;
            }

            $decoded = $decoder->decodeEvent($event);

            if ($decoded !== null) {
                $onEvent($decoded);
            }
        });
    }

    /**
     * @param array<string, mixed> $variables
     * @param Closure(TriggerEvent): void $onTrigger
     * @throws HaClientException
     */
    public function subscribeTrigger(HaTriggerCollection $triggers, array $variables, Closure $onTrigger): int
    {
        $decoder = $this->decoder;
        $logger = $this->logger;

        return $this->subscribe(new SubscribeTrigger($triggers, $variables), static function (array $event) use ($decoder, $logger, $onTrigger): void {
            $decoded = $decoder->decodeTriggerEvent($event);

            if ($decoded === null) {
                $logger->warning('Skipped a trigger event without variables.trigger');

                return;
            }

            $onTrigger($decoded);
        });
    }

    /** @throws HaClientException */
    public function unsubscribeEvents(int $subscriptionId): void
    {
        $this->connection->unsubscribeEvents($subscriptionId);
    }

    /**
     * @param Closure(array<string, mixed>): void $handler
     * @throws HaClientException
     */
    private function subscribe(SubscriptionCommand $command, Closure $handler): int
    {
        try {
            return $this->connection->subscribeEvents($command, $handler);
        } catch (HaClientException $e) {
            throw $e->reason === HaClientError::CommandUnauthorized
                ? HaClientException::administratorRequired($e)
                : $e;
        }
    }

    private function toHistoryException(EntityId $entityId, HaClientException $e): HistoryException
    {
        if ($e->reason === HaClientError::CommandRejected && $e->findErrorCode() === self::UNKNOWN_COMMAND_CODE) {
            return HistoryException::recorderUnavailable($entityId, $e);
        }

        return match ($e->reason) {
            HaClientError::CommandRejected,
            HaClientError::CommandUnauthorized,
            HaClientError::CommandUnencodable => HistoryException::rejected($entityId, $e->findDetail() ?? $e->getMessage(), $e->findErrorCode(), $e),
            HaClientError::CommandTimedOut => HistoryException::timedOut($entityId, $e->getMessage(), $e),
            default => HistoryException::unreachable($entityId, $e->getMessage(), $e),
        };
    }

    private function toServiceCallException(string $domain, string $service, HaClientException $e): ServiceCallException
    {
        return match ($e->reason) {
            HaClientError::CommandRejected,
            HaClientError::CommandUnauthorized,
            HaClientError::CommandUnencodable => ServiceCallException::rejected(
                $domain,
                $service,
                $e->findDetail() ?? $e->getMessage(),
                $e->findErrorCode(),
                $e,
            ),
            HaClientError::CommandTimedOut => ServiceCallException::timedOut($domain, $service, $e->getMessage(), $e),
            default => ServiceCallException::unreachable($domain, $service, $e->getMessage(), $e),
        };
    }
}
