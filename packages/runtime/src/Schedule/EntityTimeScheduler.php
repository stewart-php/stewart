<?php

declare(strict_types=1);

namespace Stewart\Runtime\Schedule;

use Closure;
use Psr\Log\LoggerInterface;
use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\Schedule\ScheduledRun;
use Stewart\Contracts\Selector\Selector;
use Stewart\Contracts\Time\Clock;
use Stewart\Runtime\Model\ResourceScope;
use Stewart\Runtime\State\StateCache;
use Stewart\Runtime\Worker\Context\DispatchStreams;

final readonly class EntityTimeScheduler
{
    public function __construct(
        private ScheduleRegistry $registry,
        private StateCache $states,
        private DispatchStreams $streams,
        private Clock $clock,
    ) {}

    /** @param Closure(ScheduledRun): void $handler */
    public function armForEntity(ResourceScope $scope, LoggerInterface $logger, EntityId $entityId, Closure $handler): EntityTimeTask
    {
        $task = new EntityTimeTask($this->registry->claimTaskId(), $entityId, $scope, $logger, $handler, $this->registry, $this->states, $this->clock);
        $task->rearmFor($this->states->find($entityId));
        $task->startFollowing($this->streams->watchStateChanges($scope, Selector::exact($entityId->value)));

        return $task;
    }
}
