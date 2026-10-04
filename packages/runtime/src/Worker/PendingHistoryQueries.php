<?php

declare(strict_types=1);

namespace Stewart\Runtime\Worker;

use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\Exception\HistoryException;
use Stewart\Contracts\History\Collection\HistoricalStateCollection;
use Stewart\Contracts\History\EntityStateHistory;
use Stewart\Contracts\History\HistoryWindow;
use Stewart\Runtime\Ipc\Message\HistoryFailed;
use Stewart\Runtime\Model\CorrelationId;

final class PendingHistoryQueries
{
    /** @var array<string, PendingHistoryQuery> */
    private array $pending = [];

    public function __construct(private readonly CorrelationIdSequence $correlationIds) {}

    public function open(EntityId $entityId, HistoryWindow $window): PendingHistoryQuery
    {
        $query = new PendingHistoryQuery($this->correlationIds->issueNext(), $entityId, $window);
        $this->pending[$query->correlationId->value] = $query;

        return $query;
    }

    public function resolve(CorrelationId $correlationId, HistoricalStateCollection $states): void
    {
        $query = $this->take($correlationId);
        $query?->complete(new EntityStateHistory($query->entityId, $query->window, $states));
    }

    public function reject(HistoryFailed $error): void
    {
        $this->take($error->correlationId)?->fail($error->toException());
    }

    public function forget(CorrelationId $correlationId): void
    {
        unset($this->pending[$correlationId->value]);
    }

    public function failAll(string $detail): int
    {
        $pending = $this->pending;
        $this->pending = [];

        foreach ($pending as $query) {
            $query->fail(HistoryException::unreachable($query->entityId, $detail));
        }

        return \count($pending);
    }

    private function take(CorrelationId $correlationId): ?PendingHistoryQuery
    {
        $query = $this->pending[$correlationId->value] ?? null;
        unset($this->pending[$correlationId->value]);

        return $query;
    }
}
