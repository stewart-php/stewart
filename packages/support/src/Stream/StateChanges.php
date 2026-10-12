<?php

declare(strict_types=1);

namespace Stewart\Support\Stream;

/** @internal */
final readonly class StateChanges extends ComposedStateChangeStream
{
    protected function extendWith(StreamSource $stage): static
    {
        return new static($stage, $this->timers, $this->currentStates);
    }
}
