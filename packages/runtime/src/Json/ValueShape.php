<?php

declare(strict_types=1);

namespace Stewart\Runtime\Json;

use Stewart\Contracts\Wire\WireVariants;

final readonly class ValueShape
{
    public bool $passesThrough;

    public function __construct(
        public ValueKind $kind,
        public string $type = '',
        public ?ValueShape $element = null,
        public ?ValueConverter $converter = null,
        public string $enumBackingType = '',
        public ?WireVariants $variants = null,
    ) {
        $this->passesThrough = match ($kind) {
            ValueKind::String, ValueKind::Int, ValueKind::Float, ValueKind::Number, ValueKind::Bool, ValueKind::Json => true,
            ValueKind::List => $element !== null && $element->passesThrough,
            default => false,
        };
    }

    public function describeExpectedValue(): string
    {
        return match ($this->kind) {
            ValueKind::String => 'a string',
            ValueKind::Int => 'a whole number',
            ValueKind::Float, ValueKind::Number => 'a number',
            ValueKind::Bool => 'true or false',
            ValueKind::List => 'a list',
            ValueKind::Json => 'a JSON value',
            default => 'an object',
        };
    }
}
