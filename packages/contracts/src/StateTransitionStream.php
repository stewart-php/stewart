<?php

declare(strict_types=1);

namespace Stewart\Contracts;

use Stewart\Contracts\Exception\StateException;

interface StateTransitionStream extends StateChangeStream
{
    /** @throws StateException */
    public function from(string $state, string ...$otherStates): static;

    /** @throws StateException */
    public function fromAnyState(): static;
}
