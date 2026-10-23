<?php

declare(strict_types=1);

namespace Stewart\Testing\Time;

use Amp\Cancellation;
use Amp\DeferredCancellation;
use Amp\TimeoutException;
use Closure;
use Stewart\Contracts\Time\Duration;
use Stewart\Contracts\Time\MonotonicTime;
use Stewart\Contracts\Time\PendingWait;
use Stewart\Support\Time\Deadlines;
use Stewart\Support\Time\DeferredWait;
use Stewart\Support\Time\TimerHandle;
use Stewart\Support\Time\Timers;
use Stewart\Testing\Async\HandlerFibers;

final class ManualTimers implements Timers, Deadlines
{
    /** @var array<int, ManualTimer> */
    private array $timers = [];

    public readonly HandlerFibers $handlerFibers;

    public function __construct(public readonly VirtualClock $clock = new VirtualClock())
    {
        $this->handlerFibers = new HandlerFibers();
    }

    public function startTimer(Duration $delay, Closure $callback): TimerHandle
    {
        $timer = new ManualTimer(
            $this->clock->getMonotonicTime()->plus($delay),
            $callback,
            $this->forget(...),
        );

        $this->timers[spl_object_id($timer)] = $timer;

        return $timer;
    }

    /**
     * @template T
     * @param Closure(PendingWait<T>): (Closure(): void) $arm
     * @return T|null
     */
    public function waitFor(Closure $arm, ?Duration $timeout = null): mixed
    {
        /** @var DeferredWait<T> $wait */
        $wait = new DeferredWait();
        $teardown = $arm($wait);
        $timeoutTimer = $timeout === null ? null : $this->startTimer($timeout, $wait->expire(...));

        try {
            return $wait->awaitOutcome();
        } finally {
            $timeoutTimer?->cancel();
            $teardown();
        }
    }

    public function timeout(Duration $limit): Cancellation
    {
        $deadline = new DeferredCancellation();
        $timer = $this->startTimer($limit, static function () use ($deadline): void {
            $deadline->cancel(new TimeoutException());
        });

        return new ManualTimeout($deadline->getCancellation(), $timer);
    }

    public function delay(Duration $wait, ?Cancellation $cancellation = null): void
    {
        $cancellation?->throwIfRequested();

        $target = $this->clock->getMonotonicTime()->plus($wait);

        while (($due = $this->findEarliestDueBy($target)) !== null) {
            $this->clock->moveTo($due->dueAt);
            $due->fire();
            // Fibers the timer resumed run before the next timer is due, so the timers they start count.
            EventLoopTicks::settle();
            $cancellation?->throwIfRequested();
        }

        $this->clock->moveTo($target);
    }

    public function countPendingTimers(): int
    {
        return \count($this->timers);
    }

    private function findEarliestDueBy(MonotonicTime $target): ?ManualTimer
    {
        $earliest = null;

        foreach ($this->timers as $timer) {
            if ($timer->dueAt->isAfter($target)) {
                continue;
            }

            if ($earliest === null || $timer->dueAt->isBefore($earliest->dueAt)) {
                $earliest = $timer;
            }
        }

        return $earliest;
    }

    private function forget(ManualTimer $timer): void
    {
        unset($this->timers[spl_object_id($timer)]);
    }
}
