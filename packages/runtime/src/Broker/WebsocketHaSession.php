<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker;

use Amp\DeferredCancellation;
use DateTimeZone;
use Psr\Log\LoggerInterface;
use Stewart\Client\Exception\HaClientException;
use Stewart\Client\HaClient;
use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\Event\HaEvent;
use Stewart\Contracts\History\EntityStateHistory;
use Stewart\Contracts\History\HistoryWindow;
use Stewart\Contracts\Service\ServiceResponse;
use Stewart\Contracts\Service\ServiceTarget;
use Stewart\Contracts\State\StateChange;
use Stewart\Contracts\Time\Clock;
use Stewart\Contracts\Time\MonotonicTime;
use Stewart\Runtime\Ipc\Wire\EntityStatesFragment;
use Stewart\Runtime\State\StateCache;
use Throwable;

use function Amp\async;

final class WebsocketHaSession implements HaSession
{
    private HaSessionListener $listener;

    private int $revision = 0;

    private bool $open = false;

    private readonly DeferredCancellation $stop;

    private bool $reconnecting = false;

    private bool $establishing = false;

    /** @var array<string, StateChange> */
    private array $pendingChanges = [];

    private ?DateTimeZone $timeZone = null;

    private ?StateCacheSnapshot $stateCacheSnapshot = null;

    public function __construct(
        private readonly HaClient $client,
        private readonly Reconnector $reconnector,
        private readonly LoggerInterface $logger,
        private readonly Clock $clock,
        private readonly StateCache $states,
    ) {
        $this->stop = new DeferredCancellation();
    }

    public function open(HaSessionListener $listener): void
    {
        $this->listener = $listener;
        $this->open = true;

        $this->reconnector->retryUntilConnected($this->connectSubscribeAndSeed(...), $this->stop->getCancellation());

        if (!$this->isOpen()) {
            return;
        }

        $this->client->onDisconnect($this->onDisconnected(...));

        $this->logger->info('Connected to Home Assistant', [
            'ha_version' => $this->client->getHaVersion(),
            'entities' => $this->states->count(),
            'time_zone' => $this->getTimeZone()->getName(),
        ]);
    }

    public function close(): void
    {
        $this->open = false;
        $this->stop->cancel();
        $this->client->close();
    }

    private function isOpen(): bool
    {
        return $this->open;
    }

    public function isConnected(): bool
    {
        return $this->open && !$this->reconnecting;
    }

    public function snapshotStateCache(): StateCacheSnapshot
    {
        if ($this->stateCacheSnapshot?->revision !== $this->revision) {
            $this->stateCacheSnapshot = new StateCacheSnapshot(EntityStatesFragment::fromCollection($this->states->getAllStates()), $this->revision);
        }

        return $this->stateCacheSnapshot;
    }

    public function countEntities(): int
    {
        return $this->states->count();
    }

    public function getTimeZone(): DateTimeZone
    {
        return $this->timeZone ?? new DateTimeZone('UTC');
    }

    public function getHaVersion(): ?string
    {
        return $this->client->getHaVersion();
    }

    public function listEntityIds(): array
    {
        return $this->states->listEntityIds()->toStrings();
    }

    public function callService(
        string $domain,
        string $service,
        array $data = [],
        ?ServiceTarget $target = null,
        bool $returnResponse = false,
    ): ServiceResponse {
        return $this->client->callService($domain, $service, $data, $target, $returnResponse);
    }

    public function fetchHistory(EntityId $entityId, HistoryWindow $window, bool $includeAttributes): EntityStateHistory
    {
        return $this->client->fetchHistory($entityId, $window, $includeAttributes);
    }

    private function connectSubscribeAndSeed(): void
    {
        // Subscribe before seeding and buffer changes in between, so no change is lost.
        $this->client->close();
        $this->client->connect();
        $this->establishing = true;

        try {
            $this->client->subscribeAllEvents($this->onStateChanged(...), $this->onEventFired(...));
            $this->timeZone ??= $this->client->getTimeZone();
            $states = $this->client->getStates();
            $this->client->flushEvents();

            $this->establishing = false;
            $this->states->replaceAll($states);

            foreach ($this->pendingChanges as $change) {
                $this->states->applyChange($change);
            }

            ++$this->revision;
        } finally {
            $this->establishing = false;
            $this->pendingChanges = [];
        }
    }

    private function onStateChanged(StateChange $change): void
    {
        if ($this->establishing) {
            $this->pendingChanges[$change->entityId->value] = $change;

            return;
        }

        $this->states->applyChange($change);
        ++$this->revision;

        $this->listener->stateChanged($change);
    }

    private function onEventFired(HaEvent $event): void
    {
        // Dropped until the state cache is rebuilt; handlers would read pre-outage state.
        if ($this->establishing || $this->reconnecting) {
            return;
        }

        $this->listener->eventFired($event);
    }

    private function onDisconnected(HaClientException $lost): void
    {
        if (!$this->open || $this->reconnecting) {
            return;
        }

        $this->reconnecting = true;
        $startedAt = $this->clock->getMonotonicTime();

        $this->listener->connectionLost($lost->getMessage(), $this->clock->getNow());

        async(function () use ($startedAt): void {
            try {
                $this->reconnectAfterOutage($startedAt);
            } catch (Throwable $e) {
                $this->logger->error('Failed handling a Home Assistant reconnect', ['exception' => $e]);
            }
        })->ignore();
    }

    private function reconnectAfterOutage(MonotonicTime $startedAt): void
    {
        try {
            $this->reconnector->retryUntilConnected($this->connectSubscribeAndSeed(...), $this->stop->getCancellation());
        } catch (Throwable $e) {
            $this->reconnecting = false;
            $this->listener->connectionFailed($e);

            return;
        }

        $this->reconnecting = false;

        if (!$this->isOpen()) {
            return;
        }

        $outage = $this->clock->getMonotonicTime()->elapsedSince($startedAt);

        $this->logger->info('Reconnected to Home Assistant', [
            'outage' => (string) $outage,
            'entities' => $this->states->count(),
        ]);

        $this->listener->reconnected($outage);
    }
}
