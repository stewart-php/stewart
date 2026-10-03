<?php

declare(strict_types=1);

namespace Stewart\Runtime\Time;

use Closure;
use Stewart\Contracts\Time\Duration;
use Stewart\Contracts\Time\TimerHandle;
use Stewart\Contracts\Time\Timers;
use Throwable;

final class RepeatingTimer
{
    private ?TimerHandle $pending = null;

    /**
     * @param Closure(): void $tick
     * @param Closure(Throwable): void $onTickFailed
     */
    public function __construct(
        private readonly Timers $timers,
        private readonly Duration $interval,
        private readonly Closure $tick,
        private readonly Closure $onTickFailed,
    ) {}

    public function start(): void
    {
        $this->stop();
        $this->scheduleNextTick();
    }

    public function stop(): void
    {
        $this->pending?->cancel();
        $this->pending = null;
    }

    public function isRunning(): bool
    {
        return $this->pending !== null;
    }

    private function scheduleNextTick(): void
    {
        $this->pending = $this->timers->startTimer($this->interval, function (): void {
            // Re-armed before the tick, so a tick that stops the timer stays stopped.
            $this->scheduleNextTick();

            try {
                ($this->tick)();
            } catch (Throwable $e) {
                ($this->onTickFailed)($e);
            }
        });
    }
}
