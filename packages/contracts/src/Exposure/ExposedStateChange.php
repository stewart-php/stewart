<?php

declare(strict_types=1);

namespace Stewart\Contracts\Exposure;

// A null field leaves that part of the entity as it is; an ExposedState holding null makes the state unknown.
final readonly class ExposedStateChange
{
    /** @param array<string, mixed>|null $attributes */
    public function __construct(
        public ?ExposedState $state = null,
        public ?array $attributes = null,
        public ?bool $available = null,
    ) {}

    public function withLaterChange(self $later): self
    {
        return new self($later->state ?? $this->state, $later->attributes ?? $this->attributes, $later->available ?? $this->available);
    }
}
