<?php

declare(strict_types=1);

namespace Stewart\Support\Time;

use Amp\Cancellation;
use Amp\TimeoutCancellation;
use Closure;
use Revolt\EventLoop;
use Stewart\Contracts\Time\Duration;
use Stewart\Contracts\Time\TimerHandle;
use Stewart\Contracts\Time\Timers;

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

    public function timeout(Duration $limit): Cancellation
    {
        return new TimeoutCancellation($limit->toSeconds());
    }

    public function delay(Duration $wait, ?Cancellation $cancellation = null): void
    {
        delay($wait->toSeconds(), cancellation: $cancellation);
    }
}
