<?php

declare(strict_types=1);

namespace Stewart\Support\State;

use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\State\EntityState;
use Stewart\Contracts\State\EventContext;
use Stewart\Contracts\State\StateChange;
use Stewart\Contracts\State\StateChangeOrigin;
use Stewart\Contracts\Time\Instant;

/** @internal */
final readonly class StateUpdate
{
    public function __construct(
        public EntityId $entityId,
        public ?EntityState $to,
        public ?Instant $firedAt = null,
        public ?EventContext $context = null,
        public StateChangeOrigin $origin = StateChangeOrigin::Live,
    ) {}

    public static function fromStateChange(StateChange $change): self
    {
        return new self($change->entityId, $change->to, $change->firedAt, $change->context, $change->origin);
    }

    public function toStateChange(?EntityState $from): StateChange
    {
        return new StateChange($this->entityId, $from, $this->to, $this->firedAt, $this->context, $this->origin);
    }
}
