<?php

declare(strict_types=1);

namespace Stewart\Testing\Time;

use Amp\Cancellation;
use Closure;
use Stewart\Contracts\Time\TimerHandle;

final class ManualTimeout implements Cancellation
{
    public function __construct(
        private readonly Cancellation $cancellation,
        private readonly TimerHandle $timer,
    ) {}

    // Mirrors TimeoutCancellation: an abandoned deadline must not stay pending.
    public function __destruct()
    {
        $this->timer->cancel();
    }

    public function subscribe(Closure $callback): string
    {
        return $this->cancellation->subscribe($callback);
    }

    public function unsubscribe(string $id): void
    {
        $this->cancellation->unsubscribe($id);
    }

    public function isRequested(): bool
    {
        return $this->cancellation->isRequested();
    }

    public function throwIfRequested(): void
    {
        $this->cancellation->throwIfRequested();
    }
}
