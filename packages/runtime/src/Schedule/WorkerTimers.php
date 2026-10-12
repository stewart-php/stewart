<?php

declare(strict_types=1);

namespace Stewart\Runtime\Schedule;

use Closure;
use Stewart\Contracts\Schedule\Scheduler;
use Stewart\Contracts\Time\Duration;
use Stewart\Contracts\Time\PendingWait;
use Stewart\Contracts\Time\TimerHandle;
use Stewart\Contracts\Time\Timers;

final readonly class WorkerTimers implements Timers
{
    // Waits time out on the loop timers, so a paused app's wait still ends.
    public function __construct(
        private Scheduler $scheduler,
        private Timers $loopTimers,
    ) {}

    public function startTimer(Duration $delay, Closure $callback): TimerHandle
    {
        $shortestDelay = Duration::milliseconds(1);
        $task = $this->scheduler->runAfter($shortestDelay->isLongerThan($delay) ? $shortestDelay : $delay, static function () use ($callback): void {
            $callback();
        });

        return new ScheduledTimerHandle($task);
    }

    /**
     * @template T
     * @param Closure(PendingWait<T>): (Closure(): void) $arm
     * @return T|null
     */
    public function waitFor(Closure $arm, ?Duration $timeout = null): mixed
    {
        return $this->loopTimers->waitFor($arm, $timeout);
    }
}
