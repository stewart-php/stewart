<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Time;

use Amp\Cancellation;
use Amp\DeferredFuture;
use Amp\NullCancellation;
use Stewart\Contracts\Time\Duration;
use Stewart\Support\Time\Deadlines;

// A delay that only a cancellation ends.
final class StalledDeadlines implements Deadlines
{
    private int $waiting = 0;

    public function timeout(Duration $limit): Cancellation
    {
        return new NullCancellation();
    }

    public function delay(Duration $wait, ?Cancellation $cancellation = null): void
    {
        ++$this->waiting;

        try {
            new DeferredFuture()->getFuture()->await($cancellation);
        } finally {
            --$this->waiting;
        }
    }

    public function countWaiting(): int
    {
        return $this->waiting;
    }
}
