<?php

declare(strict_types=1);

namespace Stewart\Contracts\Registry\Update;

// A null icon falls back to the icon the integration gives the entity.
final readonly class EntityIconChange
{
    public function __construct(public ?string $icon) {}
}
