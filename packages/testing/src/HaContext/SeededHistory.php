<?php

declare(strict_types=1);

namespace Stewart\Testing\HaContext;

use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\History\Collection\HistoricalStateCollection;
use Stewart\Contracts\History\EntityStateHistory;
use Stewart\Contracts\History\HistoryDetail;
use Stewart\Contracts\History\HistoryWindow;
use Stewart\Contracts\State\EntityState;
use Stewart\Contracts\Time\Instant;

final class SeededHistory
{
    /** @var array<string, list<EntityState>> */
    private array $statesByEntityId = [];

    public function recordState(EntityState $state, Instant $changedAt): void
    {
        $this->statesByEntityId[$state->entityId->value][] = new EntityState(
            $state->entityId,
            $state->state,
            $state->attributes,
            $state->lastChangedAt ?? $changedAt,
            $state->lastUpdatedAt ?? $state->lastChangedAt ?? $changedAt,
        );
    }

    public function sliceForWindow(EntityId $entityId, HistoryWindow $window, HistoryDetail $detail): EntityStateHistory
    {
        $startState = null;
        $inWindow = [];

        foreach ($this->listStatesInOrder($entityId) as $state) {
            $changedAt = $state->lastChangedAt ?? $window->startsAt;

            if (!$changedAt->isAfter($window->startsAt)) {
                $startState = new EntityState($entityId, $state->state, $state->attributes, $window->startsAt, $window->startsAt);
            } elseif (!$changedAt->isAfter($window->endsAt)) {
                $inWindow[] = $state;
            }
        }

        $states = $startState === null ? $inWindow : [$startState, ...$inWindow];

        if (!$detail->includesAttributeOnlyChanges()) {
            $states = self::dropRepeatedStates($states);
        }

        return new EntityStateHistory(
            $entityId,
            $window,
            HistoricalStateCollection::fromStates($detail->includesAttributes() ? $states : array_map(self::stripAttributes(...), $states)),
        );
    }

    /**
     * @param list<EntityState> $states
     * @return list<EntityState>
     */
    private static function dropRepeatedStates(array $states): array
    {
        $kept = [];

        foreach ($states as $state) {
            $previous = $kept[\count($kept) - 1] ?? null;

            if ($previous === null || $previous->state !== $state->state) {
                $kept[] = $state;
            }
        }

        return $kept;
    }

    /** @return list<EntityState> */
    private function listStatesInOrder(EntityId $entityId): array
    {
        $states = $this->statesByEntityId[$entityId->value] ?? [];
        usort($states, static fn(EntityState $a, EntityState $b): int => $a->lastChangedAt?->toEpochMicroseconds() <=> $b->lastChangedAt?->toEpochMicroseconds());

        return $states;
    }

    private static function stripAttributes(EntityState $state): EntityState
    {
        return new EntityState($state->entityId, $state->state, [], $state->lastChangedAt, $state->lastUpdatedAt);
    }
}
