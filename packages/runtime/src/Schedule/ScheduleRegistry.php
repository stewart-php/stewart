<?php

declare(strict_types=1);

namespace Stewart\Runtime\Schedule;

use Closure;
use Psr\Log\LoggerInterface;
use Stewart\Contracts\Schedule\ElapsedSchedule;
use Stewart\Contracts\Schedule\ScheduledRun;
use Stewart\Contracts\Schedule\ScheduledTask;
use Stewart\Contracts\Schedule\WallClockSchedule;
use Stewart\Runtime\Model\ResourceScope;
use Stewart\Runtime\Scope\ScopeLifecycle;
use Throwable;

final class ScheduleRegistry
{
    /** @var array<string, ScheduleEntry> */
    private array $entries = [];

    private int $counter = 0;

    public function __construct(
        private readonly string $scheduleIdPrefix,
        private readonly ScheduleContext $context,
        private readonly TriggerFactory $triggers,
        private readonly ScopeLifecycle $scopes,
    ) {}

    /** @param Closure(ScheduledRun): void $handler */
    public function arm(ResourceScope $scope, WallClockSchedule|ElapsedSchedule $schedule, LoggerInterface $logger, Closure $handler): ScheduledTask
    {
        $trigger = $this->triggers->createTriggerFor($schedule);
        $entry = new ScheduleEntry(
            new ScheduleOrigin($scope, $this->scheduleIdPrefix . ':' . $this->counter++, $trigger->describe()),
            $trigger,
            $logger,
            $this->context,
            $handler,
            $this->forgetEntry(...),
        );

        if ($this->scopes->isClosed($scope)) {
            $logger->debug('Ignored a schedule armed after its app stopped', ['schedule' => $entry->origin->description]);
            $entry->cancel();

            return $entry->task;
        }

        if (!$this->occursAtAll($entry)) {
            return $entry->task;
        }

        $this->entries[$entry->origin->taskId] = $entry;

        if ($this->scopes->isPaused($scope)) {
            $entry->pause();
        }

        if ($this->scopes->isLive($scope)) {
            $this->goLive($entry);
        }

        return $entry->task;
    }

    public function startEntriesOf(ResourceScope $scope): void
    {
        foreach ($this->listEntriesOf($scope) as $entry) {
            if (!$entry->live) {
                $this->goLive($entry);
            }
        }
    }

    public function pauseEntriesOf(ResourceScope $scope): void
    {
        foreach ($this->listEntriesOf($scope) as $entry) {
            $entry->pause();
        }
    }

    public function resumeEntriesOf(ResourceScope $scope): void
    {
        foreach ($this->listEntriesOf($scope) as $entry) {
            $entry->resume();
        }
    }

    public function cancelEntriesOf(ResourceScope $scope): void
    {
        foreach ($this->listEntriesOf($scope) as $entry) {
            $entry->cancel();
        }
    }

    public function cancelAll(): void
    {
        foreach ($this->entries as $entry) {
            $entry->cancel();
        }
    }

    public function count(): int
    {
        return \count($this->entries);
    }

    public function countFor(ResourceScope $scope): int
    {
        return \count($this->listEntriesOf($scope));
    }

    private function occursAtAll(ScheduleEntry $entry): bool
    {
        try {
            $occurs = $entry->occursAtAll();
        } catch (Throwable $e) {
            $this->context->listener->scheduleFailed($entry->origin, $e);
            $entry->cancel();

            return false;
        }

        if (!$occurs) {
            $entry->warnNeverOccurs();
            $entry->cancel();

            return false;
        }

        return true;
    }

    private function goLive(ScheduleEntry $entry): void
    {
        try {
            $live = $entry->activate();
        } catch (Throwable $e) {
            $this->context->listener->scheduleFailed($entry->origin, $e);
            $entry->cancel();

            return;
        }

        if (!$live) {
            $entry->cancel();
        }
    }

    private function forgetEntry(string $taskId): void
    {
        unset($this->entries[$taskId]);
    }

    /** @return array<string, ScheduleEntry> */
    private function listEntriesOf(ResourceScope $scope): array
    {
        return array_filter($this->entries, static fn(ScheduleEntry $entry): bool => $entry->origin->scope->equals($scope));
    }
}
