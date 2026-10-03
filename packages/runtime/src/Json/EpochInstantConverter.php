<?php

declare(strict_types=1);

namespace Stewart\Runtime\Json;

use Stewart\Contracts\Exception\JsonShapeException;
use Stewart\Contracts\Time\Instant;

final readonly class EpochInstantConverter implements ValueConverter
{
    public function handledClass(): string
    {
        return Instant::class;
    }

    public function keySuffix(): string
    {
        return '_us';
    }

    public function encodeValue(object $value): int
    {
        \assert($value instanceof Instant);

        return $value->toEpochMicroseconds();
    }

    public function decodeValue(mixed $value, string $path): Instant
    {
        return \is_int($value)
            ? Instant::fromEpochMicroseconds($value)
            : throw JsonShapeException::wrongType($path, 'a whole number of microseconds since the epoch', get_debug_type($value));
    }
}
