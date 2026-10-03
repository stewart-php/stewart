<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Worker;

use Stewart\Contracts\Schedule\ScheduledRun;
use Stewart\Runtime\Schedule\ScheduleListener;
use Stewart\Runtime\Schedule\ScheduleOrigin;
use Throwable;

final class RecordingScheduleListener implements ScheduleListener
{
    /** @var list<ScheduleOrigin> */
    public array $failedRuns = [];

    /** @var list<ScheduleOrigin> */
    public array $failedSchedules = [];

    /** @var list<Throwable> */
    public array $errors = [];

    /** @var list<ScheduleOrigin> */
    public array $started = [];

    public function scheduledRunFailed(ScheduleOrigin $origin, Throwable $error): void
    {
        $this->failedRuns[] = $origin;
        $this->errors[] = $error;
    }

    public function scheduledRunStarted(ScheduleOrigin $origin, ScheduledRun $run): void
    {
        $this->started[] = $origin;
    }

    public function scheduleFailed(ScheduleOrigin $origin, Throwable $error): void
    {
        $this->failedSchedules[] = $origin;
        $this->errors[] = $error;
    }
}
