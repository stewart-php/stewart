<?php

declare(strict_types=1);

namespace Stewart\Runtime\Control\Protocol\Status;

final readonly class OutboxStatus
{
    public function __construct(
        public int $queued,
        public int $dropped,
        public int $coalescedStateChanges,
        public int $stateBatchesSent,
        public int $largestStateBatch,
    ) {}

    public static function zero(): self
    {
        return new self(0, 0, 0, 0, 0);
    }

    public function plus(self $other): self
    {
        return new self(
            queued: $this->queued + $other->queued,
            dropped: $this->dropped + $other->dropped,
            coalescedStateChanges: $this->coalescedStateChanges + $other->coalescedStateChanges,
            stateBatchesSent: $this->stateBatchesSent + $other->stateBatchesSent,
            largestStateBatch: max($this->largestStateBatch, $other->largestStateBatch),
        );
    }

    public function withoutQueued(): self
    {
        return new self(0, $this->dropped, $this->coalescedStateChanges, $this->stateBatchesSent, $this->largestStateBatch);
    }
}
