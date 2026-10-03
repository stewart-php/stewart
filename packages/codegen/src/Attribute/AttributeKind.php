<?php

declare(strict_types=1);

namespace Stewart\Codegen\Attribute;

use Stewart\Codegen\Php\PhpType;

enum AttributeKind
{
    case Bool;
    case Float;
    case String;
    case Array;
    case Mixed;

    public static function ofValue(mixed $value): ?self
    {
        return match (true) {
            $value === null => null,
            \is_bool($value) => self::Bool,
            // An int reading may be followed by a fraction at run time, so every number is a float.
            \is_int($value), \is_float($value) => self::Float,
            \is_string($value) => self::String,
            \is_array($value) => self::Array,
            default => self::Mixed,
        };
    }

    public function widen(self $other): self
    {
        return $this === $other ? $this : self::Mixed;
    }

    public function getReaderMethodName(): string
    {
        return match ($this) {
            self::Bool => 'getBoolAttribute',
            self::Float => 'getFloatAttribute',
            self::String => 'getStringAttribute',
            self::Array => 'getArrayAttribute',
            self::Mixed => 'getAttribute',
        };
    }

    public function getPhpType(): PhpType
    {
        return match ($this) {
            self::Bool => PhpType::fromNative('?bool'),
            self::Float => PhpType::fromNative('?float'),
            self::String => PhpType::fromNative('?string'),
            self::Array => PhpType::fromNative('?array', 'array<array-key, mixed>|null'),
            self::Mixed => PhpType::mixed(),
        };
    }
}
