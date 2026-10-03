<?php

declare(strict_types=1);

namespace Stewart\Runtime\Control\Protocol\Status;

use Stewart\Contracts\Time\Duration;
use Stewart\Contracts\Time\Instant;
use Stewart\Contracts\Wire\ListOf;
use Stewart\Runtime\Lifecycle\WorkerPhase;

final readonly class WorkerStatus
{
    /** @param list<string> $appIds */
    public function __construct(
        public int $workerId,
        public WorkerPhase $phase,
        #[ListOf('string')]
        public array $appIds,
        public ?int $pid = null,
        public bool $ready = false,
        public int $missedProbes = 0,
        public ?Duration $loopLag = null,
        public ?int $memoryBytes = null,
        public ?Instant $lastPongAt = null,
        public int $restartsInWindow = 0,
        public ?Instant $restartDueAt = null,
        public ?OutboxStatus $outbox = null,
        public int $inFlightServiceCalls = 0,
    ) {}
}
