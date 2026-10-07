<?php

declare(strict_types=1);

namespace Stewart\Contracts\State;

use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\Time\Instant;

final readonly class StateChange
{
    public function __construct(
        public EntityId $entityId,
        public ?EntityState $from,
        public ?EntityState $to,
        public ?Instant $firedAt = null,
        public ?EventContext $context = null,
        public StateChangeOrigin $origin = StateChangeOrigin::Live,
    ) {}

    public static function fromCurrentState(EntityState $state): self
    {
        return new self($state->entityId, $state, $state, origin: StateChangeOrigin::Initial);
    }

    public function isReconstructed(): bool
    {
        return $this->origin === StateChangeOrigin::Resync;
    }

    public function isInitial(): bool
    {
        return $this->origin === StateChangeOrigin::Initial;
    }

    public function isNew(): bool
    {
        return $this->from === null && $this->to !== null;
    }

    public function isRemoved(): bool
    {
        return $this->to === null;
    }

    public function hasStateChanged(): bool
    {
        return $this->from?->state !== $this->to?->state;
    }

    public function changedTo(string $state): bool
    {
        return $this->to?->state === $state && $this->from?->state !== $state;
    }

    public function changedFrom(string $state): bool
    {
        return $this->from?->state === $state && $this->to?->state !== $state;
    }

    public function findCausingContext(): ?EventContext
    {
        return $this->context ?? $this->to?->context;
    }

    public function wasCausedBy(EventContext $context): bool
    {
        $causing = $this->findCausingContext();

        return $causing !== null && $context->isSameOrParentOf($causing);
    }

    public function getDomain(): string
    {
        return $this->entityId->domain;
    }
}
