<?php

declare(strict_types=1);

namespace Stewart\Contracts\Stream;

use Stewart\Contracts\State\StateChange;

/** @internal */
final readonly class PreviousStateRule
{
    /** @param list<string>|null $allowedStates */
    private function __construct(
        private ?array $allowedStates,
        private bool $requiresKnownPrevious,
    ) {}

    public static function excludingUnavailable(): self
    {
        return new self(null, true);
    }

    public static function allowingAny(): self
    {
        return new self(null, false);
    }

    public static function allowingOnly(string ...$states): self
    {
        return new self(array_values($states), false);
    }

    public function permits(StateChange $change): bool
    {
        $previous = $change->from;

        if ($this->allowedStates !== null) {
            return $previous !== null && \in_array($previous->state, $this->allowedStates, true);
        }

        return !$this->requiresKnownPrevious || ($previous !== null && !$previous->isUnavailable());
    }
}
