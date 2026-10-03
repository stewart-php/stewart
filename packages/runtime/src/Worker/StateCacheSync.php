<?php

declare(strict_types=1);

namespace Stewart\Runtime\Worker;

use Amp\Cancellation;
use Amp\CancelledException;
use Amp\DeferredFuture;
use Psr\Log\LoggerInterface;
use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\State\Collection\EntityStateCollection;
use Stewart\Contracts\State\Collection\StateChangeCollection;
use Stewart\Contracts\State\EntityState;
use Stewart\Contracts\State\StateChange;
use Stewart\Contracts\State\StateChangeOrigin;
use Stewart\Contracts\Time\Instant;
use Stewart\Runtime\State\StateCache;

final class StateCacheSync
{
    private int $revision = 0;

    private bool $seeded = false;

    /** @var DeferredFuture<null> */
    private readonly DeferredFuture $firstSeed;

    public function __construct(
        private readonly StateCache $stateCache,
        private readonly LoggerInterface $logger,
    ) {
        $this->firstSeed = new DeferredFuture();
    }

    public function seedFromSnapshot(EntityStateCollection $states, int $revision): void
    {
        $this->stateCache->replaceAll($states);
        $this->revision = $revision;

        if ($this->seeded) {
            return;
        }

        $this->seeded = true;

        $this->logger->debug('State cache seeded', ['entities' => $this->stateCache->count(), 'revision' => $revision]);

        $this->firstSeed->complete();
    }

    public function awaitFirstSeed(Cancellation $cancellation): bool
    {
        try {
            $this->firstSeed->getFuture()->await($cancellation);
        } catch (CancelledException) {
            return false;
        }

        return true;
    }

    public function isOlderThan(int $revision): bool
    {
        return $revision > $this->revision;
    }

    public function replaceWithResyncedStates(EntityStateCollection $states, int $revision, Instant $resyncedAt): StateChangeCollection
    {
        $reconstructed = $this->buildResyncChanges($this->stateCache->getAllStates(), $states, $resyncedAt);

        $this->stateCache->replaceAll($states);
        $this->revision = $revision;

        return $reconstructed;
    }

    public function applyStateChange(StateChange $change): void
    {
        $this->stateCache->applyChange($change);
    }

    public function countEntities(): int
    {
        return $this->stateCache->count();
    }

    private function buildResyncChanges(EntityStateCollection $before, EntityStateCollection $after, Instant $resyncedAt): StateChangeCollection
    {
        $changes = [];

        foreach ($after as $state) {
            $previous = $before->find($state->entityId);

            if ($previous === null || $this->hasChanged($previous, $state)) {
                $changes[] = $this->buildResyncChange($state->entityId, $previous, $state, $resyncedAt);
            }
        }

        foreach ($before as $state) {
            if ($after->find($state->entityId) === null) {
                $changes[] = $this->buildResyncChange($state->entityId, $state, null, $resyncedAt);
            }
        }

        return StateChangeCollection::fromChanges($changes);
    }

    private function hasChanged(EntityState $before, EntityState $after): bool
    {
        // HA bumps last_updated on any write; the fallback covers states without it.
        if ($before->lastUpdatedAt !== null && $after->lastUpdatedAt !== null) {
            return !$before->lastUpdatedAt->equals($after->lastUpdatedAt);
        }

        return $before->state !== $after->state || $before->attributes !== $after->attributes;
    }

    private function buildResyncChange(EntityId $entityId, ?EntityState $from, ?EntityState $to, Instant $resyncedAt): StateChange
    {
        return new StateChange(
            entityId: $entityId,
            from: $from,
            to: $to,
            firedAt: $to->lastUpdatedAt ?? $resyncedAt,
            context: null,
            origin: StateChangeOrigin::Resync,
        );
    }
}
