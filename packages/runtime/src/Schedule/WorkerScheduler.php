<?php

declare(strict_types=1);

namespace Stewart\Runtime\Schedule;

use Closure;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\Schedule\DayOfWeek;
use Stewart\Contracts\Schedule\ScheduledTask;
use Stewart\Contracts\Schedule\Scheduler;
use Stewart\Contracts\Schedule\TimeOfDay;
use Stewart\Contracts\Sun\SunCalendar;
use Stewart\Contracts\Sun\SunEvent;
use Stewart\Contracts\Time\Duration;
use Stewart\Contracts\Time\PendingWait;
use Stewart\Contracts\Time\SunOffset;
use Stewart\Runtime\Model\ResourceScope;
use Stewart\Support\Schedule\CalendarSchedule;
use Stewart\Support\Schedule\CronSchedule;
use Stewart\Support\Schedule\DelaySchedule;
use Stewart\Support\Schedule\ElapsedSchedule;
use Stewart\Support\Schedule\EntityTime;
use Stewart\Support\Schedule\IntervalSchedule;
use Stewart\Support\Schedule\OneShotSchedule;
use Stewart\Support\Schedule\SunEventSchedule;
use Stewart\Support\Schedule\WallClockSchedule;
use Stewart\Support\Time\Timers;

final readonly class WorkerScheduler implements Scheduler
{
    public function __construct(
        private ScheduleRegistry $registry,
        private SunCalendar $sunCalendar,
        private EntityTimeScheduler $entityTimes,
        private LoggerInterface $logger,
        private ResourceScope $resourceScope,
        private Timers $loopTimers,
    ) {}

    public function forApp(AppId $appId, LoggerInterface $logger): self
    {
        return new self($this->registry, $this->sunCalendar, $this->entityTimes, $logger, ResourceScope::forApp($appId), $this->loopTimers);
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

    public function runAtEntityTime(EntityId|string $entity, Closure $handler): ScheduledTask
    {
        $entityId = EntityId::fromStringOrId($entity);
        EntityTime::requireSupportedEntity($entityId);

        return $this->entityTimes->armForEntity($this->resourceScope, $this->logger, $entityId, $handler);
    }

    public function runAtSunEvent(SunEvent $event, Closure $handler, ?SunOffset $offset = null): ScheduledTask
    {
        return $this->armSchedule(SunEventSchedule::forEvent($this->sunCalendar, $event, $offset), $handler);
    }

    // On the loop timers rather than a schedule, so a paused app's wait still times out.
    /**
     * @template T
     * @param Closure(PendingWait<T>): (Closure(): void) $arm
     * @return T|null
     */
    public function waitFor(Closure $arm, ?Duration $timeout = null): mixed
    {
        return $this->loopTimers->waitFor($arm, $timeout);
    }

    private function armSchedule(WallClockSchedule|ElapsedSchedule $schedule, Closure $handler): ScheduledTask
    {
        return $this->registry->arm($this->resourceScope, $schedule, $this->logger, $handler);
    }
}
