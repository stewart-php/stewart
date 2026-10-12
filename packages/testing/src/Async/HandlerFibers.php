<?php

declare(strict_types=1);

namespace Stewart\Testing\Async;

use Amp\Future;
use Closure;
use Stewart\Testing\Time\EventLoopTicks;

use function Amp\async;

// Runs fake deliveries in their own fibers, so a handler that waits suspends itself instead of the test.
final class HandlerFibers
{
    /** @var list<Future<mixed>> */
    private array $suspended = [];

    /** @var list<Closure(): void> */
    private array $held = [];

    private bool $holding = false;

    /** @param Closure(): void $handler */
    public function runSettled(Closure $handler): void
    {
        if ($this->holding) {
            $this->held[] = $handler;

            return;
        }

        $run = async($handler);
        EventLoopTicks::settle();

        if (!$run->isComplete()) {
            $this->suspended[] = $run;

            return;
        }

        $run->await();
    }

    public function rethrowFailures(): void
    {
        $suspended = $this->suspended;
        $this->suspended = [];

        foreach ($suspended as $run) {
            if (!$run->isComplete()) {
                $this->suspended[] = $run;
            }
        }

        foreach ($suspended as $run) {
            if ($run->isComplete()) {
                $run->await();
            }
        }
    }

    public function holdDeliveries(): void
    {
        $this->holding = true;
    }

    public function releaseDeliveries(): void
    {
        $this->holding = false;
        $held = $this->held;
        $this->held = [];

        foreach ($held as $handler) {
            $this->runSettled($handler);
        }
    }
}
