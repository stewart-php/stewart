<?php

declare(strict_types=1);

namespace Stewart\Testing\Stream;

use Closure;
use Stewart\Contracts\Stream\StreamSource;
use Stewart\Contracts\Stream\SubscriptionScope;
use Stewart\Contracts\Subscription;

/**
 * @template T
 * @implements StreamSource<T>
 */
final class PushSource implements StreamSource
{
    /** @var array<string, Closure(T): void> */
    private array $targets = [];

    private int $counter = 0;

    public function attach(SubscriptionScope $scope, Closure $downstream): Subscription
    {
        $id = 'push:' . $this->counter++;
        $this->targets[$id] = $downstream;

        return new PushSubscription($id, function (string $cancelled) use ($scope): void {
            unset($this->targets[$cancelled]);
            $scope->close();
        });
    }

    /** @param T $event */
    public function push(mixed $event): void
    {
        foreach ($this->targets as $target) {
            $target($event);
        }
    }

    public function countSubscribers(): int
    {
        return \count($this->targets);
    }

    public function countAttachments(): int
    {
        return $this->counter;
    }
}
