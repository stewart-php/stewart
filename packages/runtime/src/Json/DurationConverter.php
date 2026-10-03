<?php

declare(strict_types=1);

namespace Stewart\Runtime\Json;

use Stewart\Contracts\Exception\JsonShapeException;
use Stewart\Contracts\Exception\TimeException;
use Stewart\Contracts\Time\Duration;

final readonly class DurationConverter implements ValueConverter
{
    private const string EXPECTED = 'a non-negative whole number of microseconds';

    public function handledClass(): string
    {
        return Duration::class;
    }

    public function keySuffix(): string
    {
        return '_us';
    }

    public function encodeValue(object $value): int
    {
        \assert($value instanceof Duration);

        return $value->toMicroseconds();
    }

    public function decodeValue(mixed $value, string $path): Duration
    {
        if (!\is_int($value)) {
            throw JsonShapeException::wrongType($path, self::EXPECTED, get_debug_type($value));
        }

        try {
            return Duration::microseconds($value);
        } catch (TimeException) {
            throw JsonShapeException::wrongType($path, self::EXPECTED, (string) $value);
        }
    }
}
