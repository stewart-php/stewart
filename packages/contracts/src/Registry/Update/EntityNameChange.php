<?php

declare(strict_types=1);

namespace Stewart\Contracts\Registry\Update;

// A null name falls back to the name the integration gives the entity.
final readonly class EntityNameChange
{
    public function __construct(public ?string $name) {}
}
