<?php

declare(strict_types=1);

namespace Stewart\Runtime\Json;

final readonly class FieldShape
{
    public function __construct(
        public string $property,
        public string $key,
        public bool $nullable,
        public ValueShape $value,
    ) {}
}
