<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker;

use DateTimeZone;
use Stewart\Client\Component\ExposedEntityDefinition;
use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\Event\EventPayload;
use Stewart\Contracts\Exception\EventFireException;
use Stewart\Contracts\Exception\ExposureException;
use Stewart\Contracts\Exception\HistoryException;
use Stewart\Contracts\Exception\ServiceCallException;
use Stewart\Contracts\Exposure\ExposedEntityKey;
use Stewart\Contracts\Exposure\ExposedEntitySnapshot;
use Stewart\Contracts\Exposure\ExposedStateChange;
use Stewart\Contracts\History\EntityStateHistory;
use Stewart\Contracts\History\HistoryDetail;
use Stewart\Contracts\History\HistoryWindow;
use Stewart\Contracts\Service\ServiceResponse;
use Stewart\Contracts\Service\ServiceTarget;
use Stewart\Contracts\State\EventContext;
use Stewart\Contracts\Sun\GeoLocation;
use Stewart\Contracts\Trigger\TriggerSpec;
use Stewart\Runtime\Broker\Exposure\ExposedEntityCommand;
use Stewart\Runtime\Model\WorkerId;
use Throwable;

interface HaSession
{
    /** @throws Throwable */
    public function open(HaSessionListener $listener): void;

    public function close(): void;

    public function isConnected(): bool;

    public function snapshotStateCache(): StateCacheSnapshot;

    public function snapshotRegistry(): RegistryCacheSnapshot;

    public function refreshRegistry(): bool;

    public function countEntities(): int;

    public function getTimeZone(): DateTimeZone;

    public function getHaVersion(): ?string;

    public function getHaUserId(): ?string;

    public function getLocation(): ?GeoLocation;

    /** @return list<string> */
    public function listEntityIds(): array;

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
    ): ServiceResponse;

    /** @throws EventFireException */
    public function fireEvent(EventPayload $payload): EventContext;

    /** @throws HistoryException */
    public function fetchHistory(EntityId $entityId, HistoryWindow $window, HistoryDetail $detail): EntityStateHistory;

    public function subscribeTrigger(TriggerSpec $spec): void;

    public function unsubscribeTrigger(TriggerSpec $spec): void;

    /** @throws ExposureException */
    public function exposeEntity(
        WorkerId $owner,
        AppId $appId,
        ExposedEntityKey $key,
        ExposedEntityDefinition $definition,
        ExposedStateChange $change,
    ): ?ExposedEntitySnapshot;

    /** @throws ExposureException */
    public function updateExposedEntity(AppId $appId, ExposedEntityKey $key, ExposedStateChange $change): void;

    public function removeExposedEntity(AppId $appId, ExposedEntityKey $key): void;

    public function orphanExposuresOfApp(AppId $appId): void;

    public function orphanExposuresOfWorker(WorkerId $workerId): void;

    public function acceptExposedEntityCommand(ExposedEntityCommand $command): void;

    public function rejectExposedEntityCommand(ExposedEntityCommand $command, string $reason): void;
}
