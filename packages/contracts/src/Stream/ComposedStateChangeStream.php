<?php

declare(strict_types=1);

namespace Stewart\Contracts\Stream;

use Closure;
use Stewart\Contracts\EventStream;
use Stewart\Contracts\State\CurrentStateReader;
use Stewart\Contracts\State\StateChange;
use Stewart\Contracts\StateChangeStream;
use Stewart\Contracts\StateTransitionStream;
use Stewart\Contracts\Time\Duration;
use Stewart\Contracts\Time\Timers;

/** @extends ComposedStream<StateChange> */
abstract readonly class ComposedStateChangeStream extends ComposedStream implements StateChangeStream
{
    /** @param StreamSource<StateChange> $source */
    public function __construct(
        StreamSource $source,
        Timers $timers,
        protected CurrentStateReader $currentStates,
    ) {
        parent::__construct($source, $timers);
    }

    public function distinctUntilChanged(): static
    {
        $key = static fn(StateChange $change): ?string => $change->to?->state;

        return $this->extendWith(new DistinctUntilChangedOperator($this->source, $key, $this->createPartitionKeyReader()));
    }

    public function startWithCurrentState(): static
    {
        return $this->extendWith(new StartWithCurrentStateOperator($this->source, $this->currentStates));
    }

    public function whenChangedTo(string $state, ?Duration $for = null): StateTransitionStream
    {
        $transition = new WhenChangedToOperator($this->timers, $this->source, $state, $for, PreviousStateRule::excludingUnavailable());

        return new StateTransitions($transition, $this->timers, $this->currentStates, $transition);
    }

    public function whenStableFor(Duration $window): static
    {
        return $this->extendWith(new WhenStableForOperator($this->timers, $this->source, $window));
    }

    public function whenAbove(float $threshold, ?Duration $for = null, ?string $attribute = null, float $hysteresis = 0.0): static
    {
        return $this->extendWith(new ThresholdCrossingOperator($this->timers, $this->source, ThresholdDirection::Above, $threshold, $for, $attribute, $hysteresis));
    }

    public function whenBelow(float $threshold, ?Duration $for = null, ?string $attribute = null, float $hysteresis = 0.0): static
    {
        return $this->extendWith(new ThresholdCrossingOperator($this->timers, $this->source, ThresholdDirection::Below, $threshold, $for, $attribute, $hysteresis));
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
