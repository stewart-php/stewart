<?php

declare(strict_types=1);

namespace Stewart\Runtime\Worker\Subject;

use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\Exception\RegistryEditException;
use Stewart\Contracts\Registry\RegisteredEntity;
use Stewart\Runtime\Worker\PendingRequestSubject;

/** @implements PendingRequestSubject<RegisteredEntity> */
final readonly class RegistryEditSubject implements PendingRequestSubject
{
    public function __construct(public EntityId $entityId) {}

    public function getResultClass(): string
    {
        return RegisteredEntity::class;
    }

    public function createUnreachableFailure(string $detail): RegistryEditException
    {
        return RegistryEditException::unreachable($this->entityId, $detail);
    }
}
