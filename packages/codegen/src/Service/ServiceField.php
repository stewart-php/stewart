<?php

declare(strict_types=1);

namespace Stewart\Codegen\Service;

use Stewart\Codegen\Php\PhpType;

final readonly class ServiceField
{
    public function __construct(
        public string $name,
        public PhpType $type,
        public bool $required,
        public ?string $label,
        public ?string $description,
        public ?string $unit,
        public int|float|null $min,
        public int|float|null $max,
    ) {}
}
