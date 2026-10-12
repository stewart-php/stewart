<?php

declare(strict_types=1);

namespace Stewart\Testing\Schedule;

use Closure;
use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\HaContext;
use Stewart\Contracts\Schedule\CalendarSchedule;
use Stewart\Contracts\Schedule\EntityTime;
use Stewart\Contracts\Schedule\OneShotSchedule;
use Stewart\Contracts\Schedule\ScheduledRun;
use Stewart\Contracts\Schedule\ScheduledTask;
use Stewart\Contracts\Schedule\WallClockSchedule;
use Stewart\Contracts\State\EntityState;
use Stewart\Contracts\State\StateChange;
use Stewart\Contracts\Subscription;
use Stewart\Contracts\Time\Clock;
use Stewart\Contracts\Time\Instant;

final class ManualEntityTimeTask implements ScheduledTask
{
    private EntityTime $armedTime;

    private ?ScheduledTask $inner = null;

    private readonly Subscription $following;

    private bool $active = true;

    /**
     * @param Closure(WallClockSchedule, Closure(ScheduledRun): void): ScheduledTask $armTask
     * @param Closure(ScheduledRun): void $handler
     */
    public function __construct(
        private readonly string $id,
        private readonly EntityId $entityId,
        HaContext $ha,
        private readonly Clock $clock,
        private readonly Closure $armTask,
        private readonly Closure $handler,
    ) {
        $this->armedTime = EntityTime::none();
        $this->rearmFor($ha->getState($entityId));
        $this->following = $ha->watchStateChanges($entityId)->subscribe(function (StateChange $change): void {
            $this->rearmFor($change->to);
        });
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function cancel(): void
    {
        $this->active = false;
        $this->inner?->cancel();
        $this->following->unsubscribe();
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function getNextRunAt(): ?Instant
    {
        return $this->active ? $this->inner?->getNextRunAt() : null;
    }

    private function rearmFor(?EntityState $state): void
    {
        $time = EntityTime::fromEntityState($state, $this->clock->getTimeZone());

        if (!$this->active || $time->equals($this->armedTime)) {
            return;
        }

        $this->inner?->cancel();
        $this->inner = null;
        $this->armedTime = $time;
        $schedule = $this->createScheduleFor($time);

        if ($schedule !== null) {
            $this->inner = ($this->armTask)($schedule, function (ScheduledRun $run): void {
                ($this->handler)(new ScheduledRun($this, $run->scheduledFor, $run->firedAt, $run->missedOccurrences));
            });
        }
    }

    private function createScheduleFor(EntityTime $time): ?WallClockSchedule
    {
        if ($time->dailyAt !== null) {
            return CalendarSchedule::dailyAt($time->dailyAt);
        }

        if ($time->moment !== null && $time->moment->isAfter($this->clock->getNow())) {
            return OneShotSchedule::fromMoment($time->moment->toDateTime($this->clock->getTimeZone()));
        }

        return null;
    }
}
