<?php

declare(strict_types=1);

namespace Stewart\Contracts;

use Closure;
use Stewart\Contracts\Exception\TimeException;
use Stewart\Contracts\Stream\Edge;
use Stewart\Contracts\Time\Duration;

/** @template-covariant T */
interface EventStream
{
    /** @param Closure(T): void $handler */
    public function subscribe(Closure $handler): Subscription;

    /** @param Closure(T): bool $predicate */
    public function filter(Closure $predicate): static;

    /**
     * @template U
     * @param Closure(T): U $mapper
     * @return EventStream<U>
     */
    public function map(Closure $mapper): EventStream;

    /** @throws TimeException */
    public function debounce(Duration $window, Edge $edge = Edge::Trailing): static;

    /** @throws TimeException */
    public function throttle(Duration $window, Edge $edge = Edge::Leading): static;

    public function take(int $count): static;

    /** @param EventStream<mixed> $notifier */
    public function takeUntil(EventStream $notifier): static;
}
