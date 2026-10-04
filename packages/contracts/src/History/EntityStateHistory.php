<?php

declare(strict_types=1);

namespace Stewart\Contracts\History;

use Countable;
use IteratorAggregate;
use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\History\Collection\HistoricalStateCollection;
use Stewart\Contracts\State\EntityState;
use Stewart\Contracts\Time\Duration;
use Stewart\Contracts\Time\Instant;
use Traversable;

/** @implements IteratorAggregate<int, EntityState> */
final readonly class EntityStateHistory implements IteratorAggregate, Countable
{
    public function __construct(
        public EntityId $entityId,
        public HistoryWindow $window,
        public HistoricalStateCollection $states,
    ) {}

    public function isEmpty(): bool
    {
        return $this->states->isEmpty();
    }

    public function count(): int
    {
        return $this->states->count();
    }

    public function getStateAtStart(): ?EntityState
    {
        return $this->states->getFirst();
    }

    public function getLastState(): ?EntityState
    {
        return $this->states->getLast();
    }

    public function hasBeenIn(string ...$states): bool
    {
        return $this->states->containsWhere(static fn(EntityState $entry): bool => \in_array($entry->state, $states, true));
    }

    public function getLastChangeTo(string $state): ?EntityState
    {
        $lastChange = null;
        $previous = null;

        foreach ($this->states as $entry) {
            if ($previous !== null && $entry->state === $state && $previous->state !== $state) {
                $lastChange = $entry;
            }

            $previous = $entry;
        }

        return $lastChange;
    }

    public function countChanges(): int
    {
        $changes = 0;
        $previous = null;

        foreach ($this->states as $entry) {
            if ($previous !== null && $entry->state !== $previous->state) {
                ++$changes;
            }

            $previous = $entry;
        }

        return $changes;
    }

    public function getDurationIn(string ...$states): Duration
    {
        $total = Duration::zero();
        $entries = $this->states->listValues();

        foreach ($entries as $index => $entry) {
            if (!\in_array($entry->state, $states, true)) {
                continue;
            }

            $next = $entries[$index + 1] ?? null;
            $segmentEnd = $next === null ? $this->window->endsAt : $this->clampToWindow($this->findEnteredAt($next));
            $total = $total->plus($segmentEnd->elapsedSince($this->clampToWindow($this->findEnteredAt($entry))));
        }

        return $total;
    }

    public function getIterator(): Traversable
    {
        return $this->states->getIterator();
    }

    private function findEnteredAt(EntityState $entry): Instant
    {
        return $entry->lastChangedAt ?? $entry->lastUpdatedAt ?? $this->window->startsAt;
    }

    private function clampToWindow(Instant $moment): Instant
    {
        return match (true) {
            $moment->isBefore($this->window->startsAt) => $this->window->startsAt,
            $moment->isAfter($this->window->endsAt) => $this->window->endsAt,
            default => $moment,
        };
    }
}
