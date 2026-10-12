<?php

declare(strict_types=1);

namespace Stewart\Runtime\Schedule;

use Stewart\Contracts\Time\Clock;
use Stewart\Support\Schedule\ElapsedSchedule;
use Stewart\Support\Schedule\WallClockSchedule;

final readonly class TriggerFactory
{
    public function __construct(private Clock $clock) {}

    public function createTriggerFor(WallClockSchedule|ElapsedSchedule $schedule): Trigger
    {
        return $schedule instanceof ElapsedSchedule
            ? new ElapsedTrigger($schedule, $this->clock)
            : new WallTrigger($schedule, $this->clock);
    }
}
