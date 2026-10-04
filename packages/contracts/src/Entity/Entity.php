<?php

declare(strict_types=1);

namespace Stewart\Contracts\Entity;

use Stewart\Contracts\Exception\HistoryException;
use Stewart\Contracts\Exception\ServiceCallException;
use Stewart\Contracts\Exception\StateException;
use Stewart\Contracts\HaContext;
use Stewart\Contracts\History\EntityStateHistory;
use Stewart\Contracts\History\HistoryQuery;
use Stewart\Contracts\Service\ServiceResponse;
use Stewart\Contracts\Service\ServiceTarget;
use Stewart\Contracts\Service\ServiceTargetSource;
use Stewart\Contracts\State\EntityState;
use Stewart\Contracts\StateChangeStream;

final readonly class Entity implements ServiceTargetSource
{
    public function __construct(
        private HaContext $ha,
        public EntityId $id,
    ) {}

    public function getDomain(): string
    {
        return $this->id->domain;
    }

    public function getObjectId(): string
    {
        return $this->id->objectId;
    }

    public function getState(): ?EntityState
    {
        return $this->ha->getState($this->id);
    }

    /** @throws StateException */
    public function requireState(): EntityState
    {
        return $this->ha->requireState($this->id);
    }

    /** @throws HistoryException */
    public function getHistory(HistoryQuery $query): EntityStateHistory
    {
        return $this->ha->getHistory($this->id, $query);
    }

    public function watchStateChanges(): StateChangeStream
    {
        return $this->ha->watchStateChanges($this->id);
    }

    public function toServiceTarget(): ServiceTarget
    {
        return ServiceTarget::forEntities($this->id);
    }

    /**
     * @param array<string, mixed> $data
     * @throws ServiceCallException
     */
    public function callService(string $service, array $data = []): void
    {
        $this->ha->callService($this->getDomain(), $service, $data, $this);
    }

    /**
     * @param array<string, mixed> $data
     * @throws ServiceCallException
     */
    public function callServiceForResponse(string $service, array $data = []): ServiceResponse
    {
        return $this->ha->callServiceForResponse($this->getDomain(), $service, $data, $this);
    }
}
