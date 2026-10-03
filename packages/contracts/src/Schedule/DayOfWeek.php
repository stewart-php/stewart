<?php

declare(strict_types=1);

namespace Stewart\Contracts\Schedule;

use DateTimeInterface;

enum DayOfWeek: int
{
    case Monday = 1;
    case Tuesday = 2;
    case Wednesday = 3;
    case Thursday = 4;
    case Friday = 5;
    case Saturday = 6;
    case Sunday = 7;

    public static function fromDateTime(DateTimeInterface $moment): self
    {
        return self::from((int) $moment->format('N'));
    }
}
