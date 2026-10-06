<?php

declare(strict_types=1);

namespace Stewart\Runtime\Worker\Subject;

use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\Exception\HistoryException;
use Stewart\Contracts\History\Collection\HistoricalStateCollection;
use Stewart\Runtime\Worker\PendingRequestSubject;

/** @implements PendingRequestSubject<HistoricalStateCollection> */
final readonly class HistoryQuerySubject implements PendingRequestSubject
{
    public function __construct(public EntityId $entityId) {}

    public function getResultClass(): string
    {
        return HistoricalStateCollection::class;
    }

    public function createUnreachableFailure(string $detail): HistoryException
    {
        return HistoryException::unreachable($this->entityId, $detail);
    }
}
