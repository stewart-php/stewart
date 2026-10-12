<?php

declare(strict_types=1);

namespace Stewart\Testing\Schedule;

use Closure;
use DateTimeImmutable;
use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\HaContext;
use Stewart\Contracts\Schedule\CalendarSchedule;
use Stewart\Contracts\Schedule\CronSchedule;
use Stewart\Contracts\Schedule\DayOfWeek;
use Stewart\Contracts\Schedule\DelaySchedule;
use Stewart\Contracts\Schedule\ElapsedSchedule;
use Stewart\Contracts\Schedule\EntityTime;
use Stewart\Contracts\Schedule\IntervalSchedule;
use Stewart\Contracts\Schedule\OneShotSchedule;
use Stewart\Contracts\Schedule\ScheduledRun;
use Stewart\Contracts\Schedule\ScheduledTask;
use Stewart\Contracts\Schedule\Scheduler;
use Stewart\Contracts\Schedule\SunEventSchedule;
use Stewart\Contracts\Schedule\TimeOfDay;
use Stewart\Contracts\Schedule\WallClockSchedule;
use Stewart\Contracts\Sun\SunCalendar;
use Stewart\Contracts\Sun\SunEvent;
use Stewart\Contracts\Time\Duration;
use Stewart\Contracts\Time\Instant;
use Stewart\Contracts\Time\SunOffset;
use Stewart\Testing\Time\ManualTimers;

// Runs schedules on virtual time; there is no pause, and an overrunning handler never skips a run.
final class ManualScheduler implements Scheduler
{
    /** @var list<ScheduledTask> */
    private array $tasks = [];

    private int $taskCounter = 0;

    public function __construct(
        private readonly ManualTimers $timers,
        private readonly SunCalendar $sunCalendar,
        private readonly HaContext $ha,
    ) {}

    public function runEvery(Duration $period, Closure $handler): ScheduledTask
    {
        return $this->armElapsedSchedule(IntervalSchedule::every($period), $handler);
    }

    public function runDailyAt(TimeOfDay|string $time, Closure $handler, DayOfWeek ...$onlyOn): ScheduledTask
    {
        return $this->armWallClockSchedule(CalendarSchedule::dailyAt($time, ...$onlyOn), $handler);
    }

    public function runOnCron(string $expression, Closure $handler): ScheduledTask
    {
        return $this->armWallClockSchedule(CronSchedule::parse($expression), $handler);
    }

    public function runAfter(Duration $delay, Closure $handler): ScheduledTask
    {
        return $this->armElapsedSchedule(DelaySchedule::after($delay), $handler);
    }

    public function runAt(DateTimeImmutable $moment, Closure $handler): ScheduledTask
    {
        return $this->armWallClockSchedule(OneShotSchedule::fromMoment($moment), $handler);
    }

    public function runAtEntityTime(EntityId|string $entity, Closure $handler): ScheduledTask
    {
        $entityId = EntityId::fromStringOrId($entity);
        EntityTime::requireSupportedEntity($entityId);

        return $this->tasks[] = new ManualEntityTimeTask($this->claimTaskId(), $entityId, $this->ha, $this->timers->clock, $this->armWallClockSchedule(...), $handler);
    }

    public function runAtSunEvent(SunEvent $event, Closure $handler, ?SunOffset $offset = null): ScheduledTask
    {
        return $this->armWallClockSchedule(SunEventSchedule::forEvent($this->sunCalendar, $event, $offset), $handler);
    }

    public function cancelAll(): void
    {
        foreach ($this->tasks as $task) {
            $task->cancel();
        }
    }

    /** @param Closure(ScheduledRun): void $handler */
    private function armElapsedSchedule(ElapsedSchedule $schedule, Closure $handler): ScheduledTask
    {
        $clock = $this->timers->clock;
        $anchor = $clock->getMonotonicTime();
        $anchoredAt = $clock->getNow();

        return $this->armTask(static function (Instant $after) use ($schedule, $anchor, $anchoredAt): ?Instant {
            $due = $schedule->findNextDueAfter($anchor, $anchor->plus($after->elapsedSince($anchoredAt)));

            return $due === null ? null : $anchoredAt->plus($due->elapsedSince($anchor));
        }, $handler);
    }

    /** @param Closure(ScheduledRun): void $handler */
    private function armWallClockSchedule(WallClockSchedule $schedule, Closure $handler): ScheduledTask
    {
        $zone = $this->timers->clock->getTimeZone();

        return $this->armTask(static function (Instant $after) use ($schedule, $zone): ?Instant {
            $next = $schedule->findNextOccurrenceAfter($after->toDateTime($zone));

            return $next === null ? null : Instant::fromDateTime($next);
        }, $handler);
    }

    /**
     * @param Closure(Instant): ?Instant $findNextRunAfter
     * @param Closure(ScheduledRun): void $handler
     */
    private function armTask(Closure $findNextRunAfter, Closure $handler): ScheduledTask
    {
        return $this->tasks[] = new ManualScheduledTask($this->claimTaskId(), $this->timers, $findNextRunAfter, $handler);
    }

    private function claimTaskId(): string
    {
        return 'manual:' . $this->taskCounter++;
    }
}
