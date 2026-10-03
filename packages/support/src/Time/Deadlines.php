<?php

declare(strict_types=1);

namespace Stewart\Support\Time;

use Amp\Cancellation;
use Stewart\Contracts\Time\Duration;

/** @internal */
interface Deadlines
{
    public function timeout(Duration $limit): Cancellation;

    // Unlike Timers::startTimer(), the wait keeps the event loop alive.
    public function delay(Duration $wait, ?Cancellation $cancellation = null): void;
}
