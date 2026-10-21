<?php

declare(strict_types=1);

namespace Stewart\Runtime\Control\Protocol\Status;

use Stewart\Contracts\Time\Instant;

final readonly class ScheduleStatus
{
    public function __construct(
        public string $appId,
        public int $workerId,
        public string $taskId,
        public string $description,
        public ?Instant $nextRunAt,
        public ?Instant $lastRunAt,
    ) {}
}
