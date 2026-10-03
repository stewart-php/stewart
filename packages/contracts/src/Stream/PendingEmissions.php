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
final class PendingEmissions
{
    /** @var array<string, PendingEmission<T>> */
    private array $pending = [];

    /** @param Closure(T): void $downstream */
    public function __construct(
        private readonly Timers $timers,
        private readonly Duration $window,
        private readonly SubscriptionScope $scope,
        private readonly int $stage,
        private readonly Closure $downstream,
    ) {}

    public function isArmed(string $key): bool
    {
        return ($this->pending[$key] ?? null)?->isArmed() ?? false;
    }

    /** @param T $event */
    public function hold(string $key, mixed $event): void
    {
        $this->pendingFor($key)->hold($event);
    }

    public function arm(string $key): void
    {
        $pending = $this->pendingFor($key);

        $pending->arm(function () use ($key, $pending): void {
            $this->settle($key, $pending);
        });
    }

    public function disarm(string $key): void
    {
        ($this->pending[$key] ?? null)?->disarm();

        unset($this->pending[$key]);
    }

    public function disarmAll(): void
    {
        foreach ($this->pending as $pending) {
            $pending->disarm();
        }

        $this->pending = [];
    }

    /** @return PendingEmission<T> */
    private function pendingFor(string $key): PendingEmission
    {
        /** @var PendingEmission<T> */
        return $this->pending[$key] ??= new PendingEmission($this->timers, $this->window, $this->scope, $this->stage);
    }

    /** @param PendingEmission<T> $pending */
    private function settle(string $key, PendingEmission $pending): void
    {
        unset($this->pending[$key]);

        $pending->flushInto($this->downstream);
    }
}
