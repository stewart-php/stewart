<?php

declare(strict_types=1);

namespace Stewart\Runtime\Json;

use Stewart\Contracts\Exception\TimeException;
use Stewart\Contracts\Time\Instant;
use Stewart\Support\Exception\JsonShapeError;
use Stewart\Support\Exception\JsonShapeException;

final readonly class IsoInstantConverter implements ValueConverter
{
    public function handledClass(): string
    {
        return Instant::class;
    }

    public function keySuffix(): string
    {
        return '';
    }

    public function encodeValue(object $value): string
    {
        \assert($value instanceof Instant);

        return $value->toIso8601();
    }

    public function decodeValue(mixed $value, string $path): Instant
    {
        if (!\is_string($value)) {
            throw JsonShapeException::wrongType($path, 'a string', get_debug_type($value));
        }

        try {
            return Instant::fromIso($value);
        } catch (TimeException $e) {
            throw JsonShapeException::createForReason(JsonShapeError::InstantInvalid, $e, key: $path);
        }
    }
}
