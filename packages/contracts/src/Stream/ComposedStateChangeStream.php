<?php

declare(strict_types=1);

namespace Stewart\Contracts\Stream;

use Closure;
use Stewart\Contracts\EventStream;
use Stewart\Contracts\State\StateChange;
use Stewart\Contracts\StateChangeStream;
use Stewart\Contracts\StateTransitionStream;
use Stewart\Contracts\Time\Duration;

/** @extends ComposedStream<StateChange> */
abstract readonly class ComposedStateChangeStream extends ComposedStream implements StateChangeStream
{
    public function distinctUntilChanged(): static
    {
        $key = static fn(StateChange $change): ?string => $change->to?->state;

        return $this->extendWith(new DistinctUntilChangedOperator($this->source, $key, $this->createPartitionKeyReader()));
    }

    public function whenChangedTo(string $state, ?Duration $for = null): StateTransitionStream
    {
        $transition = new WhenChangedToOperator($this->timers, $this->source, $state, $for, PreviousStateRule::excludingUnavailable());

        return new StateTransitions($transition, $this->timers, $transition);
    }

    public function whenStableFor(Duration $window): static
    {
        return $this->extendWith(new WhenStableForOperator($this->timers, $this->source, $window));
    }

    /** @return EventStream<StateChange> */
    public function acrossEntities(): EventStream
    {
        return new OperatorStream($this->source, $this->timers);
    }

    protected function createPartitionKeyReader(): Closure
    {
        return static fn(StateChange $change): string => $change->entityId->value;
    }
}
