<?php

declare(strict_types=1);

namespace Stewart\Runtime\Ipc\Message;

use Stewart\Contracts\Time\Instant;

final readonly class ScheduleActivityReport
{
    public function __construct(
        public string $taskId,
        public string $description,
        public ?Instant $nextRunAt,
        public ?Instant $lastRunAt,
    ) {}
}
