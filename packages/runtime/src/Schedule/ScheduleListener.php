<?php

declare(strict_types=1);

namespace Stewart\Runtime\Schedule;

use Stewart\Contracts\Schedule\ScheduledRun;
use Throwable;

interface ScheduleListener
{
    public function scheduledRunStarted(ScheduleOrigin $origin, ScheduledRun $run): void;

    public function scheduledRunSuppressed(ScheduleOrigin $origin, ScheduledRun $run): void;

    public function scheduledRunFailed(ScheduleOrigin $origin, Throwable $error): void;

    public function scheduleFailed(ScheduleOrigin $origin, Throwable $error): void;
}
