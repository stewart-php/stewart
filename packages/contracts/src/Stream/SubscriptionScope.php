<?php

declare(strict_types=1);

namespace Stewart\Contracts\Stream;

use Closure;
use SplObjectStorage;
use Stewart\Contracts\Subscription;
use Throwable;

final class SubscriptionScope
{
    /** @var Closure(Closure(): void): void */
    private Closure $sink;

    /** @var list<Closure(): void> */
    private array $teardowns = [];

    private ?Subscription $subscription = null;

    private bool $closed = false;

    private int $stages = 0;

    /** @var SplObjectStorage<object, int> */
    private SplObjectStorage $outstanding;

    private ?int $closeBelowStage = null;

    private int $runningEmissions = 0;

    public function __construct()
    {
        $this->sink = static function (Closure $emission): void {
            $emission();
        };
        $this->outstanding = new SplObjectStorage();
    }

    /** @param Closure(Closure(): void): void $sink */
    public function deliverVia(Closure $sink): void
    {
        $this->sink = $sink;
    }

    /** @param Closure(): void $emission */
    public function emit(Closure $emission): void
    {
        if ($this->closed) {
            return;
        }

        ($this->sink)(function () use ($emission): void {
            $this->runEmission($emission);
        });
    }

    /** @param Closure(): void $teardown */
    public function onTeardown(Closure $teardown): void
    {
        if ($this->closed) {
            $teardown();

            return;
        }

        $this->teardowns[] = $teardown;
    }

    public function attachedTo(Subscription $subscription): void
    {
        $this->subscription = $subscription;

        if ($this->closed) {
            $subscription->unsubscribe();
        }
    }

    public function claimStage(): int
    {
        return $this->stages++;
    }

    public function trackOutstanding(object $pending, int $stage): void
    {
        if ($this->closed) {
            return;
        }

        $this->outstanding[$pending] = $stage;
    }

    public function untrackOutstanding(object $pending): void
    {
        unset($this->outstanding[$pending]);

        $this->closeIfSettled();
    }

    public function closeWhenDownstreamSettles(int $stage): void
    {
        $this->closeBelowStage = min($this->closeBelowStage ?? $stage, $stage);

        $this->closeIfSettled();
    }

    public function isActive(): bool
    {
        return !$this->closed;
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;
        $this->outstanding = new SplObjectStorage();

        $teardowns = $this->teardowns;
        $this->teardowns = [];
        $failure = null;

        foreach ($teardowns as $teardown) {
            try {
                $teardown();
            } catch (Throwable $e) {
                $failure ??= $e;
            }
        }

        $this->subscription?->unsubscribe();

        if ($failure !== null) {
            throw $failure;
        }
    }

    /** @param Closure(): void $emission */
    private function runEmission(Closure $emission): void
    {
        if ($this->closed) {
            return;
        }

        ++$this->runningEmissions;

        try {
            $emission();
        } finally {
            --$this->runningEmissions;
            $this->closeIfSettled();
        }
    }

    private function closeIfSettled(): void
    {
        if ($this->closed || $this->closeBelowStage === null || $this->runningEmissions > 0) {
            return;
        }

        foreach ($this->outstanding as $pending) {
            if ($this->outstanding[$pending] < $this->closeBelowStage) {
                return;
            }
        }

        $this->close();
    }
}
