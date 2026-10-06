<?php

declare(strict_types=1);

namespace Stewart\Contracts\Registry;

final readonly class Label
{
    public function __construct(
        public LabelId $labelId,
        public string $name,
        public ?string $color = null,
        public ?string $icon = null,
        public ?string $description = null,
    ) {}

    public function isNamed(string $name): bool
    {
        return RegistryNames::containsName($name, $this->name);
    }
}
