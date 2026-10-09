<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker\Exposure;

use Closure;
use Psr\Log\LoggerInterface;
use Stewart\Client\Component\ComponentErrorCode;
use Stewart\Client\Component\ExposedEntityAddress;
use Stewart\Client\Component\ExposedEntityDefinition;
use Stewart\Client\Exception\HaClientException;
use Stewart\Client\HaClient;
use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Exception\ExposureException;
use Stewart\Contracts\Exposure\ExposedEntityKey;
use Stewart\Contracts\Exposure\ExposedEntitySnapshot;
use Stewart\Contracts\Exposure\ExposedStateChange;
use Stewart\Runtime\Broker\Component\ComponentTracker;
use Stewart\Runtime\Config\ExposeConfig;
use Stewart\Runtime\Lifecycle\ComponentState;
use Stewart\Runtime\Model\WorkerId;

use function Amp\async;

// Keeps every exposure for the next session; a gone app's entities stay, unavailable, until it exposes them again.
final class ExposureLink
{
    /** @var array<string, LiveExposure> */
    private array $exposuresByAddress = [];

    /** @var Closure(ExposedEntitySync): void */
    private Closure $onSynced;

    public function __construct(
        private readonly HaClient $client,
        private readonly LoggerInterface $logger,
        private readonly ComponentTracker $tracker,
        private readonly ExposeConfig $expose,
    ) {
        $this->onSynced = static function (): void {};
    }

    /** @param Closure(ExposedEntitySync): void $handler */
    public function onEntitySynced(Closure $handler): void
    {
        $this->onSynced = $handler;
    }

    /** @throws ExposureException */
    public function exposeEntity(
        WorkerId $owner,
        AppId $appId,
        ExposedEntityKey $key,
        ExposedEntityDefinition $definition,
        ExposedStateChange $change,
    ): ?ExposedEntitySnapshot {
        $this->assertComponentUsable($key);

        $live = new LiveExposure($owner, $this->createAddress($appId, $key), $definition, new ExposedStateChange(available: true)->withLaterChange($change));
        $this->exposuresByAddress[$live->address->describe()] = $live;

        if (!$this->tracker->isActive()) {
            return null;
        }

        try {
            return $this->sendUpsert($live);
        } catch (ExposureException $e) {
            unset($this->exposuresByAddress[$live->address->describe()]);

            throw $e;
        }
    }

    /** @throws ExposureException */
    public function updateEntity(AppId $appId, ExposedEntityKey $key, ExposedStateChange $change): void
    {
        $live = $this->findLive($appId, $key) ?? throw ExposureException::removed($key);

        if (!$this->tracker->isActive()) {
            $live->recordChange($change);

            return;
        }

        try {
            $this->client->updateExposedEntityState($live->address, $change);
        } catch (HaClientException $e) {
            $this->handleUpdateFailure($live, $change, $e);

            return;
        }

        $live->recordChange($change);
    }

    public function removeEntity(AppId $appId, ExposedEntityKey $key): void
    {
        $live = $this->findLive($appId, $key);

        if ($live === null) {
            return;
        }

        unset($this->exposuresByAddress[$live->address->describe()]);

        try {
            $this->client->removeExposedEntity($live->address);
        } catch (HaClientException $e) {
            $this->logger->warning('Could not remove an exposed entity from Home Assistant; it stays there until removed by hand', [
                'entity' => $live->address->describe(),
                'exception' => $e,
            ]);
        }
    }

    public function orphanExposuresOfApp(AppId $appId): void
    {
        $this->orphanExposures(static fn(LiveExposure $live): bool => $live->address->appId->equals($appId));
    }

    public function orphanExposuresOfWorker(WorkerId $workerId): void
    {
        $this->orphanExposures(static fn(LiveExposure $live): bool => $live->isOwnedBy($workerId));
    }

    /** @throws HaClientException */
    public function replayAll(): void
    {
        if (!$this->tracker->isActive()) {
            return;
        }

        foreach ($this->exposuresByAddress as $live) {
            try {
                $snapshot = $this->sendUpsert($live);
            } catch (ExposureException $e) {
                $this->logger->error('Home Assistant refused an exposed entity again', ['entity' => $live->address->describe(), 'exception' => $e]);

                continue;
            }

            if ($snapshot !== null && $this->findLive($live->address->appId, $live->address->key) === $live) {
                $this->syncOwner($live, $snapshot);
            }
        }
    }

    /** @throws ExposureException */
    private function assertComponentUsable(ExposedEntityKey $key): void
    {
        match ($this->tracker->detection->state) {
            ComponentState::Missing => throw ExposureException::componentMissing($key),
            ComponentState::ProtocolMismatch => throw ExposureException::protocolMismatch($key),
            ComponentState::Refused => throw ExposureException::componentRefused($key),
            ComponentState::Unchecked, ComponentState::Replaced, ComponentState::Active => null,
        };
    }

    /** @throws ExposureException */
    private function sendUpsert(LiveExposure $live): ?ExposedEntitySnapshot
    {
        try {
            return $this->client->upsertExposedEntity($live->address, $live->definition, $live->buildUpsertChange());
        } catch (HaClientException $e) {
            $this->throwIfAppCaused($e);
            $this->logger->debug('Exposed entity waits for the next stewart integration session', ['entity' => $live->address->describe(), 'exception' => $e]);

            return null;
        }
    }

    /** @throws ExposureException */
    private function throwIfAppCaused(HaClientException $e): void
    {
        match (ComponentErrorCode::tryFromException($e)) {
            ComponentErrorCode::InvalidConfig => throw ExposureException::configInvalid($e->findDetail() ?? $e->getMessage()),
            ComponentErrorCode::InvalidState => throw ExposureException::stateInvalid($e->findDetail() ?? $e->getMessage()),
            default => null,
        };
    }

    /** @throws ExposureException */
    private function handleUpdateFailure(LiveExposure $live, ExposedStateChange $change, HaClientException $e): void
    {
        $this->throwIfAppCaused($e);
        $live->recordChange($change);

        if (ComponentErrorCode::tryFromException($e) !== ComponentErrorCode::NotFound) {
            $this->logger->debug('Exposed entity state waits for the next stewart integration session', ['entity' => $live->address->describe(), 'exception' => $e]);

            return;
        }

        $snapshot = $this->sendUpsert($live);

        if ($snapshot !== null) {
            $this->syncOwner($live, $snapshot);
        }
    }

    /** @param Closure(LiveExposure): bool $belongs */
    private function orphanExposures(Closure $belongs): void
    {
        $orphaned = [];

        foreach ($this->exposuresByAddress as $live) {
            if (!$live->isOrphaned() && $belongs($live)) {
                $live->markOrphaned();
                $orphaned[] = $live;
            }
        }

        if ($orphaned !== [] && $this->tracker->isActive()) {
            async($this->sendUnavailable(...), $orphaned);
        }
    }

    /** @param list<LiveExposure> $orphaned */
    private function sendUnavailable(array $orphaned): void
    {
        foreach ($orphaned as $live) {
            try {
                $this->client->updateExposedEntityState($live->address, new ExposedStateChange(available: false));
            } catch (HaClientException $e) {
                $this->logger->debug('Orphaned exposed entity turns unavailable with the next stewart integration session', [
                    'entity' => $live->address->describe(),
                    'exception' => $e,
                ]);
            }
        }
    }

    private function syncOwner(LiveExposure $live, ExposedEntitySnapshot $snapshot): void
    {
        if ($live->owner !== null) {
            ($this->onSynced)(new ExposedEntitySync($live->owner, $live->address->appId, $live->address->key, $snapshot));
        }
    }

    private function findLive(AppId $appId, ExposedEntityKey $key): ?LiveExposure
    {
        return $this->exposuresByAddress[$this->createAddress($appId, $key)->describe()] ?? null;
    }

    private function createAddress(AppId $appId, ExposedEntityKey $key): ExposedEntityAddress
    {
        return new ExposedEntityAddress($this->expose->instance, $appId, $key);
    }
}
