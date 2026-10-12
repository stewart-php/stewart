<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Broker;

use Closure;
use DateTimeZone;
use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\State\Collection\EntityStateCollection;
use Stewart\Contracts\State\EntityState;
use Stewart\Contracts\Sun\GeoLocation;
use Stewart\Contracts\Trigger\TriggerEvent;
use Stewart\Runtime\Broker\Exposure\ExposedEntityCommand;
use Stewart\Runtime\Broker\HaSession;
use Stewart\Runtime\Broker\HaSessionListener;
use Stewart\Runtime\Broker\RegistryCacheSnapshot;
use Stewart\Runtime\Broker\StateCacheSnapshot;
use Stewart\Runtime\Ipc\Wire\EntityStatesFragment;
use Stewart\Runtime\Ipc\Wire\RegistryFragment;
use Stewart\Support\State\IndexedRegistry;
use Stewart\Support\Wire\TriggerSpec;
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

    public IndexedRegistry $registry;

    public int $registryRevision = 1;

    public int $registryRefreshes = 0;

    public bool $registryRefreshSucceeds = true;

    public ?Latch $registryRefreshGate = null;

    public bool $connected = true;

    /** @var list<string> */
    public array $entityIds = ['light.hall'];

    private bool $open = false;

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

    public function fireTrigger(TriggerSpec $spec, TriggerEvent $event): void
    {
        $this->listener?->triggerFired($spec, $event);
    }

    public function rejectTrigger(TriggerSpec $spec, string $reason): void
    {
        $this->listener?->triggerRejected($spec, $reason);
    }

    public function pushExposedEntityCommand(ExposedEntityCommand $command): void
    {
        $this->listener?->exposedEntityCommanded($command);
    }
}
