<?php

declare(strict_types=1);

namespace Stewart\Testing\Schedule;

use Closure;
use Stewart\Contracts\Schedule\ScheduledRun;
use Stewart\Contracts\Schedule\ScheduledTask;
use Stewart\Contracts\Time\Instant;
use Stewart\Contracts\Time\TimerHandle;
use Stewart\Testing\Time\ManualTimers;

final class ManualScheduledTask implements ScheduledTask
{
    private ?Instant $nextRunAt = null;

    private ?TimerHandle $timer = null;

    private bool $active = true;

    /**
     * @param Closure(Instant): ?Instant $findNextRunAfter
     * @param Closure(ScheduledRun): void $handler
     */
    public function __construct(
        private readonly string $id,
        private readonly ManualTimers $timers,
        private readonly Closure $findNextRunAfter,
        private readonly Closure $handler,
    ) {
        $this->armAfter($timers->clock->getNow());
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function cancel(): void
    {
        $this->active = false;
        $this->nextRunAt = null;
        $this->timer?->cancel();
        $this->timer = null;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function getNextRunAt(): ?Instant
    {
        return $this->nextRunAt;
    }

    private function armAfter(Instant $moment): void
    {
        $this->nextRunAt = ($this->findNextRunAfter)($moment);

        if ($this->nextRunAt === null) {
            $this->active = false;

            return;
        }

        $this->timer = $this->timers->startTimer($this->nextRunAt->elapsedSince($this->timers->clock->getNow()), $this->runDueOccurrence(...));
    }

    private function runDueOccurrence(): void
    {
        $scheduledFor = $this->nextRunAt;

        if (!$this->active || $scheduledFor === null) {
            return;
        }

        // The next occurrence is fixed before the handler runs, as in the worker.
        $this->armAfter($scheduledFor);
        $run = new ScheduledRun($this, $scheduledFor, $this->timers->clock->getNow(), 0);
        $handler = $this->handler;

        $this->timers->handlerFibers->runSettled(static function () use ($handler, $run): void {
            $handler($run);
        });
    }
}
