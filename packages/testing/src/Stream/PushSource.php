<?php

declare(strict_types=1);

namespace Stewart\Testing\Stream;

use Closure;
use Stewart\Contracts\Subscription;
use Stewart\Support\Stream\StreamSource;
use Stewart\Support\Stream\SubscriptionScope;
use Stewart\Testing\Async\HandlerFibers;

/**
 * @template T
 * @implements StreamSource<T>
 */
final class PushSource implements StreamSource
{
    /** @var array<string, Closure(T): void> */
    private array $targets = [];

    /** @var array<string, SubscriptionScope> */
    private array $scopes = [];

    private int $counter = 0;

    public function __construct(private readonly HandlerFibers $fibers = new HandlerFibers()) {}

    public function attach(SubscriptionScope $scope, Closure $downstream): Subscription
    {
        $id = 'push:' . $this->counter++;
        $this->targets[$id] = $downstream;
        $this->scopes[$id] = $scope;
        $scope->deliverVia($this->fibers->runSettled(...));

        return new PushSubscription($id, function (string $cancelled) use ($scope): void {
            unset($this->targets[$cancelled], $this->scopes[$cancelled]);
            $scope->close();
        });
    }

    /** @param T $event */
    public function push(mixed $event): void
    {
        foreach ($this->targets as $target) {
            $this->fibers->runSettled(static function () use ($target, $event): void {
                $target($event);
            });
        }
    }

    public function cancelAttachments(): void
    {
        $scopes = $this->scopes;
        $this->targets = [];
        $this->scopes = [];

        foreach ($scopes as $scope) {
            $scope->close();
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
