<?php

declare(strict_types=1);

namespace Stewart\Contracts\Registry;

// A null phrase stands for the entity's own name, as Home Assistant stores it.
final readonly class EntityAlias
{
    public function __construct(public ?string $phrase) {}

    public static function named(string $phrase): self
    {
        return new self($phrase);
    }

    public static function entityName(): self
    {
        return new self(null);
    }

    public function isEntityName(): bool
    {
        return $this->phrase === null;
    }

    public function equals(self $other): bool
    {
        return $this->phrase === $other->phrase;
    }
}
