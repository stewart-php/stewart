<?php

declare(strict_types=1);

namespace Stewart\Contracts\Registry;

use Stewart\Contracts\Wire\ListOf;

final readonly class Floor
{
    /** @param list<string> $aliases */
    public function __construct(
        public FloorId $floorId,
        public string $name,
        public ?int $level = null,
        #[ListOf('string')]
        public array $aliases = [],
        public ?string $icon = null,
    ) {}

    public function isNamed(string $name): bool
    {
        return RegistryNames::containsName($name, $this->name, ...$this->aliases);
    }
}
