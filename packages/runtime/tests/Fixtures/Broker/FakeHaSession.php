<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Broker;

use Amp\DeferredFuture;
use Amp\Future;
use Closure;
use DateTimeZone;
use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\History\Collection\HistoricalStateCollection;
use Stewart\Contracts\History\EntityStateHistory;
use Stewart\Contracts\History\HistoryDetail;
use Stewart\Contracts\History\HistoryWindow;
use Stewart\Contracts\Service\ServiceResponse;
use Stewart\Contracts\Service\ServiceTarget;
use Stewart\Contracts\State\Collection\EntityStateCollection;
use Stewart\Contracts\State\EntityState;
use Stewart\Contracts\Trigger\TriggerEvent;
use Stewart\Contracts\Trigger\TriggerSpec;
use Stewart\Runtime\Broker\HaSession;
use Stewart\Runtime\Broker\HaSessionListener;
use Stewart\Runtime\Broker\StateCacheSnapshot;
use Stewart\Runtime\Ipc\Wire\EntityStatesFragment;
use Stewart\Testing\Async\Latch;
use Throwable;

final class FakeHaSession implements HaSession
{
    public const string HA_USER_ID = 'stewart-user';

    public ?HaSessionListener $listener = null;

    public ?Throwable $openFailure = null;

    public ?Throwable $closeFailure = null;

    /** @var (Closure(): void)|null */
    public ?Closure $duringOpen = null;

    public int $revision = 1;

    public int $calls = 0;

    public bool $connected = true;

    /** @var list<string> */
    public array $entityIds = ['light.hall'];

    public ?Throwable $historyFailure = null;

    /** @var list<EntityState> */
    public array $historicalStates = [];

    /** @var list<TriggerSpec> */
    public array $subscribedTriggers = [];

    /** @var list<TriggerSpec> */
    public array $unsubscribedTriggers = [];

    private bool $open = false;

    /** @var DeferredFuture<TriggerSpec>|null */
    private ?DeferredFuture $nextTriggerSubscribe = null;

    private ?Latch $callLatch = null;

    /** @var DeferredFuture<null>|null */
    private ?DeferredFuture $nextCall = null;

    public static function createOpened(): self
    {
        $session = new self();
        $session->open = true;

        return $session;
    }

    public function open(HaSessionListener $listener): void
    {
        $this->listener = $listener;

        if ($this->duringOpen !== null) {
            ($this->duringOpen)();
        }

        if ($this->openFailure !== null) {
            throw $this->openFailure;
        }

        $this->open = true;
    }

    public function close(): void
    {
        $this->open = false;

        if ($this->closeFailure !== null) {
            throw $this->closeFailure;
        }
    }

    public function isOpen(): bool
    {
        return $this->open;
    }

    public function isConnected(): bool
    {
        return $this->connected;
    }

    public function snapshotStateCache(): StateCacheSnapshot
    {
        return new StateCacheSnapshot(EntityStatesFragment::fromCollection(EntityStateCollection::keyedByEntityId([new EntityState(new EntityId('light.hall'), 'on')])), $this->revision);
    }

    public function countEntities(): int
    {
        return \count($this->entityIds);
    }

    public function getTimeZone(): DateTimeZone
    {
        return new DateTimeZone('Europe/Budapest');
    }

    public function getHaVersion(): ?string
    {
        return $this->open ? '2026.8.1' : null;
    }

    public function getHaUserId(): ?string
    {
        return $this->open ? self::HA_USER_ID : null;
    }

    public function listEntityIds(): array
    {
        return $this->entityIds;
    }

    /** @return Future<null> */
    public function waitForNextCall(): Future
    {
        return ($this->nextCall ??= new DeferredFuture())->getFuture();
    }

    public function holdCalls(): Latch
    {
        return $this->callLatch = new Latch();
    }

    public function callService(
        string $domain,
        string $service,
        array $data = [],
        ?ServiceTarget $target = null,
        bool $returnResponse = false,
    ): ServiceResponse {
        ++$this->calls;

        $called = $this->nextCall;
        $this->nextCall = null;
        $called?->complete();

        $latch = $this->callLatch;
        $latch?->waitUntilOpen();

        return new ServiceResponse($domain, $service);
    }

    public function fetchHistory(EntityId $entityId, HistoryWindow $window, HistoryDetail $detail): EntityStateHistory
    {
        if ($this->historyFailure !== null) {
            throw $this->historyFailure;
        }

        return new EntityStateHistory($entityId, $window, HistoricalStateCollection::fromStates($this->historicalStates));
    }

    public function subscribeTrigger(TriggerSpec $spec): void
    {
        $this->subscribedTriggers[] = $spec;

        $subscribed = $this->nextTriggerSubscribe;
        $this->nextTriggerSubscribe = null;
        $subscribed?->complete($spec);
    }

    public function unsubscribeTrigger(TriggerSpec $spec): void
    {
        $this->unsubscribedTriggers[] = $spec;
    }

    /** @return Future<TriggerSpec> */
    public function waitForNextTriggerSubscribe(): Future
    {
        return ($this->nextTriggerSubscribe ??= new DeferredFuture())->getFuture();
    }

    public function fireTrigger(TriggerSpec $spec, TriggerEvent $event): void
    {
        $this->listener?->triggerFired($spec, $event);
    }

    public function rejectTrigger(TriggerSpec $spec, string $reason): void
    {
        $this->listener?->triggerRejected($spec, $reason);
    }
}
