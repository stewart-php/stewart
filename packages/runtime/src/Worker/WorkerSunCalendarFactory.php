<?php

declare(strict_types=1);

namespace Stewart\Runtime\Worker;

use Stewart\Contracts\Sun\SunCalendar;
use Stewart\Contracts\Time\Clock;
use Stewart\Runtime\Ipc\Message\Bootstrap;
use Stewart\Sun\LocatedSunCalendar;
use Stewart\Sun\NoaaSolarCalculator;
use Stewart\Sun\UnlocatedSunCalendar;

final readonly class WorkerSunCalendarFactory
{
    public function __construct(
        private Bootstrap $bootstrap,
        private Clock $clock,
        private NoaaSolarCalculator $calculator,
    ) {}

    public function createSunCalendar(): SunCalendar
    {
        if ($this->bootstrap->location === null) {
            return new UnlocatedSunCalendar();
        }

        return new LocatedSunCalendar($this->bootstrap->location, $this->clock, $this->calculator);
    }
}
