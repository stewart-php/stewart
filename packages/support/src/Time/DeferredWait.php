<?php

declare(strict_types=1);

namespace Stewart\Support\Time;

use Amp\DeferredFuture;
use Stewart\Contracts\Time\PendingWait;
use Throwable;

/**
 * @internal
 * @template T
 * @implements PendingWait<T>
 */
final readonly class DeferredWait implements PendingWait
{
    /** @var DeferredFuture<T|null> */
    private DeferredFuture $outcome;

    public function __construct()
    {
        $this->outcome = new DeferredFuture();
    }

    public function resolve(mixed $value): void
    {
        if (!$this->outcome->isComplete()) {
            $this->outcome->complete($value);
        }
    }

    public function fail(Throwable $reason): void
    {
        if (!$this->outcome->isComplete()) {
            $this->outcome->error($reason);
        }
    }

    public function expire(): void
    {
        if (!$this->outcome->isComplete()) {
            $this->outcome->complete(null);
        }
    }

    /** @return T|null */
    public function awaitOutcome(): mixed
    {
        return $this->outcome->getFuture()->await();
    }
}
