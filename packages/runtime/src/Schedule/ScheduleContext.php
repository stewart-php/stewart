<?php

declare(strict_types=1);

namespace Stewart\Runtime\Schedule;

use Stewart\Contracts\Time\Clock;
use Stewart\Contracts\Time\Timers;
use Stewart\Runtime\Logging\EveryNthOccurrence;

final readonly class ScheduleContext
{
    public EveryNthOccurrence $overlapReports;

    public function __construct(
        public Timers $timers,
        public Clock $clock,
        public HandlerRunner $runner,
        public ScheduleListener $listener,
    ) {
        $this->overlapReports = EveryNthOccurrence::forRepeatedWarnings();
    }
}
