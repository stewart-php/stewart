<?php

declare(strict_types=1);

namespace Stewart\Contracts\Stream;

use Closure;
use Stewart\Contracts\Time\Duration;
use Stewart\Contracts\Time\Timers;

/**
 * @internal
 * @template T
 */
final class ThrottleWindow
{
    /** @var PendingEmission<T> */
    private readonly PendingEmission $pending;

    /**
     * @param Closure(T): void $downstream
     * @param Closure(): void $onClosed
     */
    public function __construct(
        Timers $timers,
        Duration $windowLength,
        SubscriptionScope $scope,
        int $stage,
        private readonly Closure $downstream,
        private readonly Closure $onClosed,
    ) {
        $this->pending = new PendingEmission($timers, $windowLength, $scope, $stage);
    }

    public function isOpen(): bool
    {
        return $this->pending->isArmed();
    }

    /** @param T $event */
    public function holdEvent(mixed $event): void
    {
        $this->pending->hold($event);
    }

    public function openWindow(): void
    {
        $this->pending->arm($this->settleWindow(...));
    }

    public function cancelWindowTimer(): void
    {
        $this->pending->disarm();
    }

    private function settleWindow(): void
    {
        if (!$this->pending->flushInto($this->reopenAndForward(...))) {
            ($this->onClosed)();
        }
    }

    /** @param T $held */
    private function reopenAndForward(mixed $held): void
    {
        $this->openWindow();

        ($this->downstream)($held);
    }
}
