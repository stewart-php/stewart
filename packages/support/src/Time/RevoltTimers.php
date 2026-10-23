<?php

declare(strict_types=1);

namespace Stewart\Support\Time;

use Amp\Cancellation;
use Amp\TimeoutCancellation;
use Closure;
use Revolt\EventLoop;
use Stewart\Contracts\Time\Duration;
use Stewart\Contracts\Time\PendingWait;

use function Amp\delay;

/** @internal */
final class RevoltTimers implements Timers, Deadlines
{
    public function startTimer(Duration $delay, Closure $callback): TimerHandle
    {
        $handle = new RevoltTimerHandle();

        $identifier = EventLoop::delay($delay->toSeconds(), static function () use ($handle, $callback): void {
            $handle->markFired();

            $callback();
        });

        EventLoop::unreference($identifier);

        $handle->attachWatcher($identifier);

        return $handle;
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
        // Referenced, unlike startTimer(), so a wait in {main} keeps the loop running until it ends.
        $timeoutWatcher = $timeout === null ? null : EventLoop::delay($timeout->toSeconds(), $wait->expire(...));

        try {
            return $wait->awaitOutcome();
        } finally {
            if ($timeoutWatcher !== null) {
                EventLoop::cancel($timeoutWatcher);
            }

            $teardown();
        }
    }

    public function timeout(Duration $limit): Cancellation
    {
        return new TimeoutCancellation($limit->toSeconds());
    }

    public function delay(Duration $wait, ?Cancellation $cancellation = null): void
    {
        delay($wait->toSeconds(), cancellation: $cancellation);
    }
}
