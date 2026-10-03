<?php

declare(strict_types=1);

namespace Stewart\Contracts\Exception;

enum ScheduleError: string implements ExceptionReason
{
    case CronInvalid = 'cron_invalid';
    case TimeOfDayInvalid = 'time_of_day_invalid';
    case TimeOfDayOutOfRange = 'time_of_day_out_of_range';

    public function messageTemplate(): string
    {
        return match ($this) {
            self::CronInvalid => 'Cron expression "{expression}" is invalid.',
            self::TimeOfDayInvalid => 'Time of day "{value}" is invalid; expected "HH:MM" or "HH:MM:SS".',
            self::TimeOfDayOutOfRange => 'Time of day {unit} {value} is outside 0-{highest}.',
        };
    }
}
