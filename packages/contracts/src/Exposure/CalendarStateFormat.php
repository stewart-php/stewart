<?php

declare(strict_types=1);

namespace Stewart\Contracts\Exposure;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Stewart\Contracts\Exception\ScheduleException;
use Stewart\Contracts\Schedule\TimeOfDay;

final class CalendarStateFormat
{
    private const string DATE_FORMAT = 'Y-m-d';

    public static function formatTime(TimeOfDay $time): string
    {
        return $time->format();
    }

    public static function parseTime(string $state): ?TimeOfDay
    {
        try {
            $time = TimeOfDay::parse($state);
        } catch (ScheduleException) {
            return null;
        }

        return $time->format() === $state ? $time : null;
    }

    public static function formatDate(DateTimeInterface $date): string
    {
        return $date->format(self::DATE_FORMAT);
    }

    // Midnight UTC of the day, or null when the state is not a calendar date.
    public static function parseDate(string $state): ?DateTimeImmutable
    {
        return self::parseStrictly('!' . self::DATE_FORMAT, $state, new DateTimeZone('UTC'));
    }

    public static function formatDateTime(DateTimeInterface $moment): string
    {
        return $moment->format(DateTimeInterface::ATOM);
    }

    public static function parseDateTime(string $state): ?DateTimeImmutable
    {
        return self::parseStrictly(DateTimeInterface::ATOM, $state, null);
    }

    private static function parseStrictly(string $format, string $state, ?DateTimeZone $zone): ?DateTimeImmutable
    {
        $parsed = DateTimeImmutable::createFromFormat($format, $state, $zone);

        // Overflowing fields such as month 13 parse with warnings; the round trip rejects them.
        return $parsed !== false && $parsed->format(ltrim($format, '!')) === $state ? $parsed : null;
    }
}
