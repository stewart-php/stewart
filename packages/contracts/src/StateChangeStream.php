<?php

declare(strict_types=1);

namespace Stewart\Contracts;

use Stewart\Contracts\Exception\StateException;
use Stewart\Contracts\Exception\TimeException;
use Stewart\Contracts\State\StateChange;
use Stewart\Contracts\Time\Duration;

/** @extends EventStream<StateChange> */
interface StateChangeStream extends EventStream
{
    public function distinctUntilChanged(): static;

    public function startWithCurrentState(): static;

    /** @throws TimeException */
    public function whenChangedTo(string $state, ?Duration $for = null): StateTransitionStream;

    /** @throws TimeException */
    public function whenStableFor(Duration $window): static;

    /** @throws StateException|TimeException */
    public function whenAbove(float $threshold, ?Duration $for = null, ?string $attribute = null, float $hysteresis = 0.0): static;

    /** @throws StateException|TimeException */
    public function whenBelow(float $threshold, ?Duration $for = null, ?string $attribute = null, float $hysteresis = 0.0): static;

    /** @return EventStream<StateChange> */
    public function acrossEntities(): EventStream;
}
