<?php

declare(strict_types=1);

namespace Stewart\Runtime\Ipc\Wire;

use Stewart\Contracts\Exposure\CalendarStateFormat;
use Stewart\Contracts\Schedule\TimeOfDay;
use Stewart\Runtime\Json\ValueConverter;
use Stewart\Support\Exception\JsonShapeException;

final readonly class TimeOfDayConverter implements ValueConverter
{
    public function handledClass(): string
    {
        return TimeOfDay::class;
    }

    public function keySuffix(): string
    {
        return '';
    }

    public function encodeValue(object $value): string
    {
        \assert($value instanceof TimeOfDay);

        return CalendarStateFormat::formatTime($value);
    }

    public function decodeValue(mixed $value, string $path): TimeOfDay
    {
        if (!\is_string($value)) {
            throw JsonShapeException::wrongType($path, 'an HH:MM:SS time', get_debug_type($value));
        }

        return CalendarStateFormat::parseTime($value) ?? throw JsonShapeException::unexpectedValue($path, 'an HH:MM:SS time', $value);
    }
}
