<?php

declare(strict_types=1);

namespace Stewart\Contracts\Stream;

final readonly class StateChanges extends ComposedStateChangeStream
{
    protected function extendWith(StreamSource $stage): static
    {
        return new static($stage, $this->timers);
    }
}
