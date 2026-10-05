<?php

declare(strict_types=1);

namespace Stewart\Contracts\Exception;

enum SunError: string implements ExceptionReason
{
    case CoordinateOutOfRange = 'coordinate_out_of_range';
    case LocationUnknown = 'location_unknown';

    public function messageTemplate(): string
    {
        return match ($this) {
            self::CoordinateOutOfRange => 'The {coordinate} {value} is outside -{limit} to {limit}.',
            self::LocationUnknown => 'Home Assistant reported no location, so sun times are unknown.',
        };
    }
}
