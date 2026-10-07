<?php

declare(strict_types=1);

namespace Stewart\Contracts\Exception;

use Throwable;

/** @extends StewartException<ScheduleError> */
final class ScheduleException extends StewartException
{
    public static function cronInvalid(string $expression, Throwable $previous): self
    {
        return self::createForReason(ScheduleError::CronInvalid, ['expression' => $expression], $previous);
    }

    public static function entityTimeDomainUnsupported(string $entityId): self
    {
        return self::createForReason(ScheduleError::EntityTimeDomainUnsupported, ['entityId' => $entityId]);
    }

    public static function sunLocationUnknown(string $event, Throwable $previous): self
    {
        return self::createForReason(ScheduleError::SunLocationUnknown, ['event' => $event], $previous);
    }

    public static function timeOfDayInvalid(string $value): self
    {
        return self::createForReason(ScheduleError::TimeOfDayInvalid, ['value' => $value]);
    }

    public static function timeOfDayOutOfRange(string $unit, int $value, int $highest): self
    {
        return self::createForReason(ScheduleError::TimeOfDayOutOfRange, ['unit' => $unit, 'value' => $value, 'highest' => $highest]);
    }

    public static function timeWindowEmpty(string $time): self
    {
        return self::createForReason(ScheduleError::TimeWindowEmpty, ['time' => $time]);
    }
}
