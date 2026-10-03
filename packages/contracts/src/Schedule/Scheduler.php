<?php

declare(strict_types=1);

namespace Stewart\Contracts\Schedule;

use Closure;
use DateTimeImmutable;
use Stewart\Contracts\Exception\ScheduleException;
use Stewart\Contracts\Time\Duration;

interface Scheduler
{
    /** @param Closure(ScheduledRun): void $handler */
    public function runEvery(Duration $period, Closure $handler): ScheduledTask;

    /**
     * @param Closure(ScheduledRun): void $handler
     * @throws ScheduleException
     */
    public function runDailyAt(TimeOfDay|string $time, Closure $handler, DayOfWeek ...$onlyOn): ScheduledTask;

    /**
     * @param Closure(ScheduledRun): void $handler
     * @throws ScheduleException
     */
    public function runOnCron(string $expression, Closure $handler): ScheduledTask;

    /** @param Closure(ScheduledRun): void $handler */
    public function runAfter(Duration $delay, Closure $handler): ScheduledTask;

    /** @param Closure(ScheduledRun): void $handler */
    public function runAt(DateTimeImmutable $moment, Closure $handler): ScheduledTask;
}
