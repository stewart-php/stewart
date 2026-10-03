<?php

declare(strict_types=1);

namespace Stewart\Runtime\Schedule;

use Stewart\Contracts\Schedule\ScheduledTask;
use Stewart\Contracts\Time\Instant;

final readonly class ScheduledTaskHandle implements ScheduledTask
{
    public function __construct(private ScheduleEntry $entry) {}

    public function getId(): string
    {
        return $this->entry->origin->taskId;
    }

    public function cancel(): void
    {
        $this->entry->cancel();
    }

    public function isActive(): bool
    {
        return $this->entry->active;
    }

    public function getNextRunAt(): ?Instant
    {
        return $this->entry->getNextRunAt();
    }
}
