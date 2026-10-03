<?php

declare(strict_types=1);

namespace Stewart\Runtime\Schedule;

use Closure;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Schedule\CalendarSchedule;
use Stewart\Contracts\Schedule\CronSchedule;
use Stewart\Contracts\Schedule\DayOfWeek;
use Stewart\Contracts\Schedule\DelaySchedule;
use Stewart\Contracts\Schedule\ElapsedSchedule;
use Stewart\Contracts\Schedule\IntervalSchedule;
use Stewart\Contracts\Schedule\OneShotSchedule;
use Stewart\Contracts\Schedule\ScheduledTask;
use Stewart\Contracts\Schedule\Scheduler;
use Stewart\Contracts\Schedule\TimeOfDay;
use Stewart\Contracts\Schedule\WallClockSchedule;
use Stewart\Contracts\Time\Duration;
use Stewart\Runtime\Model\ResourceScope;

final readonly class WorkerScheduler implements Scheduler
{
    public function __construct(
        private ScheduleRegistry $registry,
        private LoggerInterface $logger,
        private ResourceScope $resourceScope,
    ) {}

    public function forApp(AppId $appId, LoggerInterface $logger): self
    {
        return new self($this->registry, $logger, ResourceScope::forApp($appId));
    }

    public function runEvery(Duration $period, Closure $handler): ScheduledTask
    {
        return $this->armSchedule(IntervalSchedule::every($period), $handler);
    }

    public function runDailyAt(TimeOfDay|string $time, Closure $handler, DayOfWeek ...$onlyOn): ScheduledTask
    {
        return $this->armSchedule(CalendarSchedule::dailyAt($time, ...$onlyOn), $handler);
    }

    public function runOnCron(string $expression, Closure $handler): ScheduledTask
    {
        return $this->armSchedule(CronSchedule::parse($expression), $handler);
    }

    public function runAfter(Duration $delay, Closure $handler): ScheduledTask
    {
        return $this->armSchedule(DelaySchedule::after($delay), $handler);
    }

    public function runAt(DateTimeImmutable $moment, Closure $handler): ScheduledTask
    {
        return $this->armSchedule(OneShotSchedule::fromMoment($moment), $handler);
    }

    private function armSchedule(WallClockSchedule|ElapsedSchedule $schedule, Closure $handler): ScheduledTask
    {
        return $this->registry->arm($this->resourceScope, $schedule, $this->logger, $handler);
    }
}
