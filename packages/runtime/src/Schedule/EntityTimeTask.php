<?php

declare(strict_types=1);

namespace Stewart\Runtime\Schedule;

use Closure;
use Psr\Log\LoggerInterface;
use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\Schedule\CalendarSchedule;
use Stewart\Contracts\Schedule\EntityTime;
use Stewart\Contracts\Schedule\OneShotSchedule;
use Stewart\Contracts\Schedule\ScheduledRun;
use Stewart\Contracts\Schedule\ScheduledTask;
use Stewart\Contracts\Schedule\WallClockSchedule;
use Stewart\Contracts\State\EntityState;
use Stewart\Contracts\State\StateChange;
use Stewart\Contracts\StateChangeStream;
use Stewart\Contracts\Subscription;
use Stewart\Contracts\Time\Clock;
use Stewart\Contracts\Time\Instant;
use Stewart\Runtime\Model\ResourceScope;
use Stewart\Runtime\State\StateCache;

final class EntityTimeTask implements ScheduledTask
{
    private EntityTime $armedTime;

    private ?ScheduledTask $inner = null;

    private ?Subscription $following = null;

    private bool $cancelled = false;

    /** @param Closure(ScheduledRun): void $handler */
    public function __construct(
        private readonly string $id,
        private readonly EntityId $entityId,
        private readonly ResourceScope $scope,
        private readonly LoggerInterface $logger,
        private readonly Closure $handler,
        private readonly ScheduleRegistry $registry,
        private readonly StateCache $states,
        private readonly Clock $clock,
    ) {
        $this->armedTime = EntityTime::none();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function cancel(): void
    {
        $this->cancelled = true;
        $this->inner?->cancel();
        $this->following?->unsubscribe();
    }

    public function isActive(): bool
    {
        return !$this->cancelled && ($this->inner?->isActive() ?? false);
    }

    public function getNextRunAt(): ?Instant
    {
        return $this->cancelled ? null : $this->inner?->getNextRunAt();
    }

    public function rearmFor(?EntityState $state): void
    {
        $time = EntityTime::fromEntityState($state, $this->clock->getTimeZone());

        if ($this->cancelled || $time->equals($this->armedTime)) {
            return;
        }

        $this->inner?->cancel();
        $this->inner = null;
        $this->armedTime = $time;
        $schedule = $this->createScheduleFor($time);

        if ($schedule === null) {
            $this->logger->debug('Entity holds no upcoming time; waiting for its next change', ['entity' => $this->entityId->value]);

            return;
        }

        $this->inner = $this->registry->arm($this->scope, $schedule, $this->logger, $this->runIfTimeIsCurrent(...));
    }

    public function startFollowing(StateChangeStream $changes): void
    {
        $this->following = $changes->subscribe(function (StateChange $change): void {
            $this->rearmFor($change->to);
        });

        if ($this->cancelled) {
            $this->following->unsubscribe();
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

    private function runIfTimeIsCurrent(ScheduledRun $run): void
    {
        $current = $this->states->find($this->entityId);

        // A paused app misses state changes, so the cache decides whether this run is still wanted.
        if (!EntityTime::fromEntityState($current, $this->clock->getTimeZone())->equals($this->armedTime)) {
            $this->rearmFor($current);

            return;
        }

        ($this->handler)(new ScheduledRun($this, $run->scheduledFor, $run->firedAt, $run->missedOccurrences));
    }
}
