<?php

declare(strict_types=1);

namespace Stewart\Contracts\Stream;

use Stewart\Contracts\Exception\StateException;
use Stewart\Contracts\State\StateChange;
use Stewart\Contracts\StateTransitionStream;
use Stewart\Contracts\Time\Timers;

final readonly class StateTransitions extends ComposedStateChangeStream implements StateTransitionStream
{
    /** @param StreamSource<StateChange> $source */
    public function __construct(
        StreamSource $source,
        Timers $timers,
        private ?WhenChangedToOperator $transition = null,
    ) {
        parent::__construct($source, $timers);
    }

    public function from(string $state, string ...$otherStates): static
    {
        return $this->withPreviousStateRule(PreviousStateRule::allowingOnly($state, ...$otherStates));
    }

    public function fromAnyState(): static
    {
        return $this->withPreviousStateRule(PreviousStateRule::allowingAny());
    }

    protected function extendWith(StreamSource $stage): static
    {
        return new static($stage, $this->timers);
    }

    private function withPreviousStateRule(PreviousStateRule $rule): static
    {
        if ($this->transition === null) {
            throw StateException::transitionAlreadyExtended();
        }

        $transition = $this->transition->withPreviousStateRule($rule);

        return new static($transition, $this->timers, $transition);
    }
}
