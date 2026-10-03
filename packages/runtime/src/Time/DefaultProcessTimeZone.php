<?php

declare(strict_types=1);

namespace Stewart\Runtime\Time;

use DateTimeZone;

final readonly class DefaultProcessTimeZone implements ProcessTimeZone
{
    public function useAsProcessDefault(DateTimeZone $zone): void
    {
        // Makes logs, app code and Instant::toDateTime() use Home Assistant's zone.
        date_default_timezone_set($zone->getName());
    }
}
