<?php

declare(strict_types=1);

namespace Stewart\Contracts\Exception;

enum ScheduleError: string implements ExceptionReason
{
    case CronInvalid = 'cron_invalid';
    case SunLocationUnknown = 'sun_location_unknown';
    case TimeOfDayInvalid = 'time_of_day_invalid';
    case TimeOfDayOutOfRange = 'time_of_day_out_of_range';
    case TimeWindowEmpty = 'time_window_empty';

    public function messageTemplate(): string
    {
        return match ($this) {
            self::CronInvalid => 'Cron expression "{expression}" is invalid.',
            self::SunLocationUnknown => 'A {event} schedule needs a location, and Home Assistant reported none.',
            self::TimeOfDayInvalid => 'Time of day "{value}" is invalid; expected "HH:MM" or "HH:MM:SS".',
            self::TimeOfDayOutOfRange => 'Time of day {unit} {value} is outside 0-{highest}.',
            self::TimeWindowEmpty => 'Time window starts and ends at {time}; start and end must differ.',
        };
    }
}
