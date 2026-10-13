<?php

declare(strict_types=1);

namespace Stewart\Runtime\Ipc\Wire;

use DateTimeImmutable;
use Stewart\Contracts\Exposure\CalendarStateFormat;
use Stewart\Runtime\Json\ValueConverter;
use Stewart\Support\Exception\JsonShapeException;

final readonly class DateTimeImmutableConverter implements ValueConverter
{
    public function handledClass(): string
    {
        return DateTimeImmutable::class;
    }

    public function keySuffix(): string
    {
        return '';
    }

    public function encodeValue(object $value): string
    {
        \assert($value instanceof DateTimeImmutable);

        return CalendarStateFormat::formatDateTime($value);
    }

    public function decodeValue(mixed $value, string $path): DateTimeImmutable
    {
        if (!\is_string($value)) {
            throw JsonShapeException::wrongType($path, 'an ISO 8601 date and time', get_debug_type($value));
        }

        return CalendarStateFormat::parseDateTime($value) ?? throw JsonShapeException::unexpectedValue($path, 'an ISO 8601 date and time with an offset', $value);
    }
}
