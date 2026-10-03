<?php

declare(strict_types=1);

namespace Stewart\Runtime\Schedule;

use Closure;
use Stewart\Contracts\Schedule\Scheduler;
use Stewart\Contracts\Time\Duration;
use Stewart\Contracts\Time\TimerHandle;
use Stewart\Contracts\Time\Timers;

final readonly class WorkerTimers implements Timers
{
    public function __construct(private Scheduler $scheduler) {}

    public function startTimer(Duration $delay, Closure $callback): TimerHandle
    {
        $shortestDelay = Duration::milliseconds(1);
        $task = $this->scheduler->runAfter($shortestDelay->isLongerThan($delay) ? $shortestDelay : $delay, static function () use ($callback): void {
            $callback();
        });

        return new ScheduledTimerHandle($task);
    }
}
