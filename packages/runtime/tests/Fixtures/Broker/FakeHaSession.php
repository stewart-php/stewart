<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Broker;

use Amp\DeferredFuture;
use Amp\Future;
use Closure;
use DateTimeZone;
use Stewart\Client\Component\ExposedEntityDefinition;
use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\Event\EventPayload;
use Stewart\Contracts\Exception\ExposureException;
use Stewart\Contracts\Exposure\ExposedEntityKey;
use Stewart\Contracts\Exposure\ExposedEntitySnapshot;
use Stewart\Contracts\Exposure\ExposedStateChange;
use Stewart\Contracts\History\Collection\HistoricalStateCollection;
use Stewart\Contracts\History\EntityStateHistory;
use Stewart\Contracts\History\HistoryDetail;
use Stewart\Contracts\History\HistoryWindow;
use Stewart\Contracts\Registry\IndexedRegistry;
use Stewart\Contracts\Service\ServiceResponse;
use Stewart\Contracts\Service\ServiceTarget;
use Stewart\Contracts\State\Collection\EntityStateCollection;
use Stewart\Contracts\State\EntityState;
use Stewart\Contracts\State\EventContext;
use Stewart\Contracts\Sun\GeoLocation;
use Stewart\Contracts\Trigger\TriggerEvent;
use Stewart\Contracts\Trigger\TriggerSpec;
use Stewart\Runtime\Broker\HaSession;
use Stewart\Runtime\Broker\HaSessionListener;
use Stewart\Runtime\Broker\RegistryCacheSnapshot;
use Stewart\Runtime\Broker\StateCacheSnapshot;
use Stewart\Runtime\Ipc\Wire\EntityStatesFragment;
use Stewart\Runtime\Ipc\Wire\RegistryFragment;
use Stewart\Runtime\Model\WorkerId;
use Stewart\Testing\Async\Latch;
use Throwable;

final class FakeHaSession implements HaSession
{
    public const string HA_USER_ID = 'stewart-user';

    public const string CALL_CONTEXT_PREFIX = 'call-';

    public const string FIRE_CONTEXT_PREFIX = 'fire-';

    public ?HaSessionListener $listener = null;

    public ?Throwable $openFailure = null;

    public ?Throwable $closeFailure = null;

    /** @var (Closure(): void)|null */
    public ?Closure $duringOpen = null;

    public int $revision = 1;

    public IndexedRegistry $registry;

    public int $registryRevision = 1;

    public int $registryRefreshes = 0;

    public bool $registryRefreshSucceeds = true;

    public ?Latch $registryRefreshGate = null;

    public int $calls = 0;

    /** @var list<ReceivedServiceCall> */
    public array $receivedCalls = [];

    /** @var list<ReceivedEventFire> */
    public array $receivedEventFires = [];

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

    /** @var array<string, ExposedStateChange> */
    public array $exposedEntities = [];

    public ?ExposedEntitySnapshot $exposedSnapshot = null;

    public ?ExposureException $exposureFailure = null;

    /** @var DeferredFuture<ExposedStateChange>|null */
    private ?DeferredFuture $nextExposedUpdate = null;

    private bool $open = false;

    /** @var DeferredFuture<TriggerSpec>|null */
    private ?DeferredFuture $nextTriggerSubscribe = null;

    private ?Latch $callLatch = null;

    /** @var DeferredFuture<null>|null */
    private ?DeferredFuture $nextCall = null;

    public function __construct()
    {
        $this->registry = IndexedRegistry::empty();
    }

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

    public function snapshotRegistry(): RegistryCacheSnapshot
    {
        return new RegistryCacheSnapshot(RegistryFragment::fromRegistry($this->registry), $this->registryRevision);
    }

    public function refreshRegistry(): bool
    {
        ++$this->registryRefreshes;
        $this->registryRefreshGate?->waitUntilOpen();

        if (!$this->registryRefreshSucceeds) {
            return false;
        }

        ++$this->registryRevision;

        return true;
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

    public function getLocation(): ?GeoLocation
    {
        return $this->open ? new GeoLocation(47.4979, 19.0402) : null;
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
        $this->receivedCalls[] = new ReceivedServiceCall($domain, $service, $data);

        $called = $this->nextCall;
        $this->nextCall = null;
        $called?->complete();

        $latch = $this->callLatch;
        $latch?->waitUntilOpen();

        return new ServiceResponse($domain, $service, context: new EventContext(self::CALL_CONTEXT_PREFIX . $this->calls, userId: self::HA_USER_ID));
    }

    public function fireEvent(EventPayload $payload): EventContext
    {
        $this->receivedEventFires[] = new ReceivedEventFire($payload->eventType, $payload->data);
        $this->callLatch?->waitUntilOpen();

        return new EventContext(self::FIRE_CONTEXT_PREFIX . \count($this->receivedEventFires), userId: self::HA_USER_ID);
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

    public function exposeEntity(
        WorkerId $owner,
        AppId $appId,
        ExposedEntityKey $key,
        ExposedEntityDefinition $definition,
        ExposedStateChange $change,
    ): ?ExposedEntitySnapshot {
        if ($this->exposureFailure !== null) {
            throw $this->exposureFailure;
        }

        $this->exposedEntities[$appId . '/' . $key] = $change;

        return $this->exposedSnapshot;
    }

    public function updateExposedEntity(AppId $appId, ExposedEntityKey $key, ExposedStateChange $change): void
    {
        $address = $appId . '/' . $key;
        $this->exposedEntities[$address] = ($this->exposedEntities[$address] ?? new ExposedStateChange())->withLaterChange($change);

        $updated = $this->nextExposedUpdate;
        $this->nextExposedUpdate = null;
        $updated?->complete($this->exposedEntities[$address]);
    }

    /** @return Future<ExposedStateChange> */
    public function waitForNextExposedUpdate(): Future
    {
        return ($this->nextExposedUpdate ??= new DeferredFuture())->getFuture();
    }

    public function removeExposedEntity(AppId $appId, ExposedEntityKey $key): void
    {
        unset($this->exposedEntities[$appId . '/' . $key]);
    }

    public function forgetExposuresOfApp(AppId $appId): void
    {
        $this->exposedEntities = array_filter(
            $this->exposedEntities,
            static fn(string $address): bool => !str_starts_with($address, $appId . '/'),
            \ARRAY_FILTER_USE_KEY,
        );
    }

    public function forgetExposuresOfWorker(WorkerId $workerId): void {}
}
