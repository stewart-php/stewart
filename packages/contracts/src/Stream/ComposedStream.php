<?php

declare(strict_types=1);

namespace Stewart\Contracts\Stream;

use Closure;
use Stewart\Contracts\EventStream;
use Stewart\Contracts\Subscription;
use Stewart\Contracts\Time\Duration;
use Stewart\Contracts\Time\Timers;

/**
 * @template T
 * @implements EventStream<T>
 */
abstract readonly class ComposedStream implements EventStream
{
    /** @param StreamSource<T> $source */
    public function __construct(
        protected StreamSource $source,
        protected Timers $timers,
    ) {}

    final public function subscribe(Closure $handler): Subscription
    {
        $scope = new SubscriptionScope();
        $subscription = $this->source->attach($scope, $handler);
        $scope->attachedTo($subscription);

        return $subscription;
    }

    public function filter(Closure $predicate): static
    {
        return $this->extendWith(new FilterOperator($this->source, $predicate));
    }

    /**
     * @template U
     * @param Closure(T): U $mapper
     * @return OperatorStream<U>
     */
    public function map(Closure $mapper): OperatorStream
    {
        return new OperatorStream(new MapOperator($this->source, $mapper), $this->timers);
    }

    public function debounce(Duration $window, Edge $edge = Edge::Trailing): static
    {
        return $this->extendWith(new DebounceOperator($this->timers, $this->source, $window, $edge, $this->createPartitionKeyReader()));
    }

    public function throttle(Duration $window, Edge $edge = Edge::Leading): static
    {
        return $this->extendWith(new ThrottleOperator($this->timers, $this->source, $window, $edge, $this->createPartitionKeyReader()));
    }

    public function take(int $count): static
    {
        return $this->extendWith(new TakeOperator($this->source, $count));
    }

    public function takeUntil(EventStream $notifier): static
    {
        return $this->extendWith(new TakeUntilOperator($this->source, $notifier));
    }

    /**
     * @param StreamSource<T> $stage
     */
    abstract protected function extendWith(StreamSource $stage): static;

    /** @return Closure(T): string */
    abstract protected function createPartitionKeyReader(): Closure;
}
