<?php

declare(strict_types=1);

namespace Stewart\Contracts\Exception;

use Throwable;

/** @extends StewartException<JsonShapeError> */
final class JsonShapeException extends StewartException
{
    public static function wrongType(string $key, string $expected, string $actualType): self
    {
        return self::createForReason(JsonShapeError::WrongType, ['key' => $key, 'expected' => $expected, 'actualType' => $actualType]);
    }

    public static function missing(string $key, string $expected): self
    {
        return self::createForReason(JsonShapeError::Missing, ['key' => $key, 'expected' => $expected]);
    }

    public static function unexpectedValue(string $key, string $expected, mixed $value): self
    {
        $shown = \is_string($value) ? '"' . $value . '"' : (\is_scalar($value) ? var_export($value, true) : get_debug_type($value));

        return self::createForReason(JsonShapeError::UnexpectedValue, ['key' => $key, 'expected' => $expected, 'value' => $shown]);
    }

    public static function instantInvalid(string $key, Throwable $previous): self
    {
        return self::createForReason(JsonShapeError::InstantInvalid, ['key' => $key], $previous);
    }
}
