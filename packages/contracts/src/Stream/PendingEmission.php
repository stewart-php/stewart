<?php

declare(strict_types=1);

namespace Stewart\Contracts\Stream;

use Closure;
use Stewart\Contracts\Time\Duration;
use Stewart\Contracts\Time\TimerHandle;
use Stewart\Contracts\Time\Timers;

/**
 * @internal
 * @template T
 */
final class PendingEmission
{
    private ?TimerHandle $timer = null;

    private int $generation = 0;

    private bool $armed = false;

    private bool $holding = false;

    /** @var T|null */
    private mixed $event = null;

    public function __construct(
        private readonly Timers $timers,
        private readonly Duration $window,
        private readonly SubscriptionScope $scope,
        private readonly int $stage,
    ) {}

    /** @param T $event */
    public function hold(mixed $event): void
    {
        $this->event = $event;
        $this->holding = true;

        $this->syncOutstanding();
    }

    public function isArmed(): bool
    {
        return $this->armed;
    }

    /** @param Closure(): void $onSettle */
    public function arm(Closure $onSettle): void
    {
        $this->timer?->cancel();
        $generation = ++$this->generation;
        $this->armed = true;

        $this->syncOutstanding();

        // The timer only queues the decision, so events queued before it still count as inside the window.
        $this->timer = $this->timers->startTimer($this->window, function () use ($generation, $onSettle): void {
            $this->scope->emit(function () use ($generation, $onSettle): void {
                $this->settleIfCurrent($generation, $onSettle);
            });
        });
    }

    public function disarm(): void
    {
        $this->timer?->cancel();
        $this->timer = null;
        ++$this->generation;
        $this->armed = false;
        $this->event = null;
        $this->holding = false;

        $this->syncOutstanding();
    }

    /** @param Closure(T): void $downstream */
    public function flushInto(Closure $downstream): bool
    {
        if (!$this->holding) {
            return false;
        }

        /** @var T $held */
        $held = $this->event;
        $this->event = null;
        $this->holding = false;

        $this->syncOutstanding();

        $downstream($held);

        return true;
    }

    /** @param Closure(): void $onSettle */
    private function settleIfCurrent(int $generation, Closure $onSettle): void
    {
        if ($generation !== $this->generation) {
            return;
        }

        $this->timer = null;
        $this->armed = false;

        $this->syncOutstanding();

        $onSettle();
    }

    private function syncOutstanding(): void
    {
        if ($this->armed && $this->holding) {
            $this->scope->trackOutstanding($this, $this->stage);

            return;
        }

        $this->scope->untrackOutstanding($this);
    }
}
