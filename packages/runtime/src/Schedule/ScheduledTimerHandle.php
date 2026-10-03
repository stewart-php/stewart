<?php

declare(strict_types=1);

namespace Stewart\Runtime\Schedule;

use Stewart\Contracts\Schedule\ScheduledTask;
use Stewart\Contracts\Time\TimerHandle;

final readonly class ScheduledTimerHandle implements TimerHandle
{
    public function __construct(private ScheduledTask $task) {}

    public function cancel(): void
    {
        $this->task->cancel();
    }

    public function isPending(): bool
    {
        return $this->task->isActive();
    }
}
