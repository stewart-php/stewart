<?php

declare(strict_types=1);

namespace Stewart\Runtime\Schedule;

use Closure;
use Psr\Log\LoggerInterface;
use Stewart\Contracts\Schedule\ScheduledRun;
use Stewart\Contracts\Schedule\ScheduledTask;
use Stewart\Contracts\Time\Duration;
use Stewart\Contracts\Time\Instant;
use Stewart\Contracts\Time\TimerHandle;
use Throwable;

final class ScheduleEntry
{
    public readonly ScheduledTask $task;

    public private(set) bool $active = true;

    public private(set) bool $live = false;

    public private(set) bool $paused = false;

    private bool $running = false;

    /** @var (Closure(ScheduledRun): void)|null */
    private ?Closure $handler;

    private ?TimerHandle $timer = null;

    private int $overlapSkips = 0;

    /**
     * @param Closure(ScheduledRun): void $handler
     * @param Closure(self): void $onCancel
     */
    public function __construct(
        public readonly ScheduleOrigin $origin,
        private readonly Trigger $trigger,
        private readonly LoggerInterface $logger,
        private readonly ScheduleContext $context,
        Closure $handler,
        private readonly Closure $onCancel,
    ) {
        $this->handler = $handler;
        $this->task = new ScheduledTaskHandle($this);
    }

    public function warnNeverOccurs(): void
    {
        $this->logger->warning('A schedule never occurs; it was not armed', ['schedule' => $this->origin->description]);
    }

    /** @throws Throwable */
    public function occursAtAll(): bool
    {
        return $this->trigger->occursAtAll();
    }

    /** @throws Throwable */
    public function activate(): bool
    {
        if (!$this->trigger->start()) {
            return false;
        }

        $this->live = true;
        $this->armTimerForNextOccurrence();

        return true;
    }

    public function pause(): void
    {
        $this->paused = true;
    }

    public function resume(): void
    {
        $this->paused = false;
    }

    public function getNextRunAt(): ?Instant
    {
        return $this->active ? $this->trigger->dueAt() : null;
    }

    public function cancel(): void
    {
        if (!$this->active) {
            return;
        }

        $this->active = false;
        $this->handler = null;
        $this->timer?->cancel();
        $this->timer = null;

        ($this->onCancel)($this);
    }

    private function armTimerForNextOccurrence(): void
    {
        $delay = $this->trigger->delayUntilDue();

        if ($delay === null || !$this->active) {
            return;
        }

        $floor = Duration::milliseconds(1);
        $this->timer = $this->context->timers->startTimer($floor->isLongerThan($delay) ? $floor : $delay, $this->onTimerFired(...));
    }

    private function onTimerFired(): void
    {
        $this->timer = null;

        if (!$this->active) {
            return;
        }

        try {
            if ($this->trigger->isDue()) {
                $this->runDueOccurrence();
            }
        } catch (Throwable $e) {
            $this->context->listener->scheduleFailed($this->origin, $e);
            $this->cancel();
        }

        $this->armTimerForNextOccurrence();
    }

    // An advance() failure propagates to onTimerFired(), which reports it and cancels before any handler runs.
    private function runDueOccurrence(): void
    {
        // The next occurrence is fixed before the handler runs, so a slow handler cannot shift it.
        $handler = $this->handler;
        $scheduledFor = $this->trigger->dueAt();
        $missed = $this->trigger->advance();

        if ($handler !== null && $scheduledFor !== null) {
            $this->startHandlerRun($handler, $scheduledFor, $missed);
        }

        if ($this->trigger->dueAt() !== null) {
            return;
        }

        if ($this->trigger->isRecurring()) {
            $this->logger->warning('A schedule has no further occurrences and has stopped', [
                'schedule' => $this->origin->description,
            ]);
        }

        $this->cancel();
    }

    /** @param Closure(ScheduledRun): void $handler */
    private function startHandlerRun(Closure $handler, Instant $scheduledFor, int $missed): void
    {
        $run = new ScheduledRun($this->task, $scheduledFor, $this->context->clock->getNow(), $missed);

        if ($this->paused) {
            $this->context->listener->scheduledRunSuppressed($this->origin, $run);

            return;
        }

        if ($this->running) {
            $this->reportOverlap($run);

            return;
        }

        if ($run->missedOccurrences > 0) {
            $this->logger->info('A scheduled run started late; the occurrences it overran were skipped', [
                'schedule' => $this->origin->description,
                'due' => $run->scheduledFor->toIso8601(),
                'late_ms' => $run->getLateness()->toMilliseconds(),
                'missed' => $run->missedOccurrences,
            ]);
        }

        $this->running = true;
        $this->overlapSkips = 0;
        $this->context->listener->scheduledRunStarted($this->origin, $run);

        $this->context->runner->run(function () use ($handler, $run): void {
            try {
                $handler($run);
            } catch (Throwable $e) {
                $this->context->listener->scheduledRunFailed($this->origin, $e);
            } finally {
                $this->running = false;
            }
        });
    }

    private function reportOverlap(ScheduledRun $run): void
    {
        $skips = ++$this->overlapSkips;
        $context = [
            'schedule' => $this->origin->description,
            'due' => $run->scheduledFor->toIso8601(),
            'skipped' => $skips,
        ];

        if ($skips === 1) {
            $this->logger->warning('Skipped a scheduled run; the previous one has not finished', $context);
        } elseif ($this->context->overlapReports->includesOccurrence($skips)) {
            $this->logger->debug('Still skipping scheduled runs; the previous one has not finished', $context);
        }
    }
}
