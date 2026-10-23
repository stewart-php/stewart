<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker;

use LogicException;
use Stewart\Contracts\Time\Instant;
use Stewart\Runtime\Control\Protocol\Status\OutboxStatus;
use Stewart\Runtime\Lifecycle\WorkerPhase;
use Stewart\Support\Time\TimerHandle;

final class WorkerSlotState
{
    public private(set) WorkerPhase $phase = WorkerPhase::Stopped;

    public private(set) ?WorkerHandle $handle = null;

    private ?TimerHandle $readyDeadline = null;

    private ?TimerHandle $restartTimer = null;

    public private(set) ?Instant $restartDueAt = null;

    public private(set) int $restarts = 0;

    public private(set) int $quarantines = 0;

    private bool $replacementRequested = false;

    private bool $retirementRequested = false;

    private OutboxStatus $retiredOutboxes;

    public function __construct(
        public private(set) WorkerSlot $slot,
        public readonly WorkerPoolListener $listener,
    ) {
        $this->retiredOutboxes = OutboxStatus::zero();
    }

    public function isLive(WorkerHandle $handle): bool
    {
        return $this->phase === WorkerPhase::Live && $this->handle === $handle;
    }

    public function isSpawning(): bool
    {
        return $this->phase === WorkerPhase::Spawning;
    }

    public function isStopping(): bool
    {
        return $this->phase === WorkerPhase::Stopping;
    }

    public function getOutboxStatus(): OutboxStatus
    {
        $total = $this->retiredOutboxes->plus($this->handle?->getOutboxStatus() ?? OutboxStatus::zero());

        return $this->phase === WorkerPhase::Live ? $total : $total->withoutQueued();
    }

    public function beginSpawn(): void
    {
        $this->moveTo(WorkerPhase::Spawning);
        $this->cancelRestart();
        $this->replacementRequested = false;
    }

    public function markSpawnFailed(): void
    {
        $this->moveTo(WorkerPhase::Stopped, from: WorkerPhase::Spawning);
    }

    public function recordSpawnedHandle(WorkerHandle $handle, ?TimerHandle $readyDeadline): ?WorkerHandle
    {
        $this->moveTo(WorkerPhase::Live);

        $previous = $this->handle;

        if ($previous !== null) {
            $this->retiredOutboxes = $this->retiredOutboxes->plus($previous->getOutboxStatus()->withoutQueued());
        }

        $this->handle = $handle;
        $this->readyDeadline = $readyDeadline;

        return $previous;
    }

    public function markGone(): void
    {
        $this->moveTo(WorkerPhase::Stopped, from: WorkerPhase::Live);
        $this->cancelReadyDeadline();
    }

    public function beginStopping(): WorkerHandle
    {
        $handle = $this->beginReplacing();
        ++$this->restarts;

        return $handle;
    }

    public function beginReplacing(): WorkerHandle
    {
        $this->moveTo(WorkerPhase::Stopping);
        $this->cancelReadyDeadline();

        return $this->handle ?? throw new LogicException(\sprintf('Worker %d is stopping without a process.', $this->slot->workerId->value));
    }

    public function replaceSlot(WorkerSlot $slot): void
    {
        if (!$slot->workerId->equals($this->slot->workerId)) {
            throw new LogicException(\sprintf('Worker %d cannot take the slot of worker %d.', $this->slot->workerId->value, $slot->workerId->value));
        }

        $this->slot = $slot;
        $this->retirementRequested = false;
    }

    public function requestReplacement(): void
    {
        $this->replacementRequested = true;
    }

    public function requestRetirement(): void
    {
        $this->retirementRequested = true;
    }

    public function isRetiring(): bool
    {
        return $this->retirementRequested;
    }

    public function takeReplacementRequest(): bool
    {
        $requested = $this->replacementRequested;
        $this->replacementRequested = false;

        return $requested;
    }

    public function recordScheduledRestart(TimerHandle $timer, Instant $dueAt): void
    {
        $this->moveTo(WorkerPhase::RestartScheduled);
        $this->restartTimer = $timer;
        $this->restartDueAt = $dueAt;
        ++$this->restarts;
    }

    public function markQuarantined(): void
    {
        $this->moveTo(WorkerPhase::Quarantined);
        ++$this->quarantines;
    }

    public function stop(): void
    {
        if ($this->phase !== WorkerPhase::Stopped) {
            $this->moveTo(WorkerPhase::Stopped);
        }

        $this->cancelRestart();
        $this->cancelReadyDeadline();
    }

    public function cancelReadyDeadline(): void
    {
        $this->readyDeadline?->cancel();
        $this->readyDeadline = null;
    }

    private function moveTo(WorkerPhase $next, ?WorkerPhase $from = null): void
    {
        if (!$this->phase->canEnter($next) || ($from !== null && $this->phase !== $from)) {
            throw new LogicException(\sprintf('Worker %d cannot move from %s to %s.', $this->slot->workerId->value, $this->phase->value, $next->value));
        }

        $this->phase = $next;
    }

    private function cancelRestart(): void
    {
        $this->restartTimer?->cancel();
        $this->restartTimer = null;
        $this->restartDueAt = null;
    }
}
