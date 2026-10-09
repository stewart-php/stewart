<?php

declare(strict_types=1);

namespace Stewart\Runtime\Ipc\Wire;

use Stewart\Contracts\Exception\JsonShapeException;
use Stewart\Contracts\Exposure\ExposedState;
use Stewart\Runtime\Json\ValueConverter;

// Wrapped in an object so an unknown state stays apart from a missing one.
final readonly class ExposedStateConverter implements ValueConverter
{
    public function handledClass(): string
    {
        return ExposedState::class;
    }

    public function keySuffix(): string
    {
        return '';
    }

    /** @return array{value: int|float|string|bool|null} */
    public function encodeValue(object $value): array
    {
        \assert($value instanceof ExposedState);

        return ['value' => $value->value];
    }

    /** @throws JsonShapeException */
    public function decodeValue(mixed $value, string $path): ExposedState
    {
        if (!\is_array($value) || !\array_key_exists('value', $value)) {
            throw JsonShapeException::wrongType($path, 'an object with a value', get_debug_type($value));
        }

        $state = $value['value'];

        if (!\is_scalar($state) && $state !== null) {
            throw JsonShapeException::wrongType($path . '.value', 'a scalar or null', get_debug_type($state));
        }

        return new ExposedState($state);
    }
}
