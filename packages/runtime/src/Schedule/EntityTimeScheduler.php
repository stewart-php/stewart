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

final class EntityTimeScheduler
{
    /** @var array<string, EntityTimeTask> */
    private array $tasksById = [];

    public function __construct(
        private readonly ScheduleRegistry $registry,
        private readonly StateCache $states,
        private readonly DispatchStreams $streams,
        private readonly Clock $clock,
    ) {}

    /** @param Closure(ScheduledRun): void $handler */
    public function armForEntity(ResourceScope $scope, LoggerInterface $logger, EntityId $entityId, Closure $handler): EntityTimeTask
    {
        $task = new EntityTimeTask(
            $this->registry->claimTaskId(),
            $entityId,
            $scope,
            $logger,
            $handler,
            $this->registry,
            $this->states,
            $this->clock,
            $this->forgetTask(...),
        );
        $this->tasksById[$task->getId()] = $task;

        $task->rearmFromCache();
        $task->startFollowing($this->streams->watchStateChanges($scope, Selector::exact($entityId->value)));

        return $task;
    }

    public function refreshTasksOf(ResourceScope $scope): void
    {
        foreach ($this->listTasksOf($scope) as $task) {
            $task->rearmFromCache();
        }
    }

    public function cancelTasksOf(ResourceScope $scope): void
    {
        foreach ($this->listTasksOf($scope) as $task) {
            $task->cancel();
        }
    }

    public function cancelAll(): void
    {
        foreach ($this->tasksById as $task) {
            $task->cancel();
        }
    }

    public function countFor(ResourceScope $scope): int
    {
        return \count($this->listTasksOf($scope));
    }

    private function forgetTask(EntityTimeTask $task): void
    {
        unset($this->tasksById[$task->getId()]);
    }

    /** @return array<string, EntityTimeTask> */
    private function listTasksOf(ResourceScope $scope): array
    {
        return array_filter($this->tasksById, static fn(EntityTimeTask $task): bool => $task->scope->equals($scope));
    }
}
