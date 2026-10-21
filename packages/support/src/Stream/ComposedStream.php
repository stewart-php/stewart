<?php

declare(strict_types=1);

namespace Stewart\Support\Stream;

use Closure;
use Stewart\Contracts\EventStream;
use Stewart\Contracts\Exception\WaitError;
use Stewart\Contracts\Exception\WaitException;
use Stewart\Contracts\Stream\Edge;
use Stewart\Contracts\Subscription;
use Stewart\Contracts\Time\Duration;
use Stewart\Contracts\Time\PendingWait;
use Stewart\Contracts\Time\Timers;

/**
 * @internal
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
        return $this->attachWithin(new SubscriptionScope(), $handler);
    }

    /** @return T|null */
    final public function waitForNext(?Duration $timeout = null): mixed
    {
        return $this->timers->waitFor(function (PendingWait $wait): Closure {
            $scope = new SubscriptionScope();
            // Registered before attaching, so a stream that completes at once fails the wait instead of hanging it.
            $scope->onTeardown(static function () use ($wait): void {
                $wait->fail(WaitException::createForReason(WaitError::Abandoned));
            });
            $this->attachWithin($scope, $wait->resolve(...));

            return $scope->close(...);
        }, $timeout);
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

    /** @param Closure(T): void $handler */
    private function attachWithin(SubscriptionScope $scope, Closure $handler): Subscription
    {
        $subscription = $this->source->attach($scope, $scope->wrapHandlerToReportCalls($handler));
        $scope->attachedTo($subscription);

        return $subscription;
    }

    /**
     * @param StreamSource<T> $stage
     */
    abstract protected function extendWith(StreamSource $stage): static;

    /** @return Closure(T): string */
    abstract protected function createPartitionKeyReader(): Closure;
}
