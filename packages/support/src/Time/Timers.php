<?php

declare(strict_types=1);

namespace Stewart\Support\Time;

use Closure;
use Stewart\Contracts\Time\Duration;
use Stewart\Contracts\Time\PendingWait;

/** @internal */
interface Timers
{
    /** @param Closure(): void $callback */
    public function startTimer(Duration $delay, Closure $callback): TimerHandle;

    // Only the first resolve() or fail() counts; the closure $arm returns runs once the wait ends, however it ends.
    /**
     * @template T
     * @param Closure(PendingWait<T>): (Closure(): void) $arm
     * @return T|null
     */
    public function waitFor(Closure $arm, ?Duration $timeout = null): mixed;
}
