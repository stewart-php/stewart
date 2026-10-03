<?php

declare(strict_types=1);

namespace Stewart\Testing\Async;

use Amp\Cancellation;
use Amp\DeferredFuture;

final class Latch
{
    /** @var DeferredFuture<null> */
    private readonly DeferredFuture $opened;

    private int $waiters = 0;

    public function __construct()
    {
        $this->opened = new DeferredFuture();
    }

    public function waitUntilOpen(?Cancellation $cancellation = null): void
    {
        ++$this->waiters;

        try {
            $this->opened->getFuture()->await($cancellation);
        } finally {
            --$this->waiters;
        }
    }

    public function open(): void
    {
        if (!$this->opened->isComplete()) {
            $this->opened->complete();
        }
    }

    public function isOpen(): bool
    {
        return $this->opened->isComplete();
    }

    public function countWaiters(): int
    {
        return $this->waiters;
    }
}
