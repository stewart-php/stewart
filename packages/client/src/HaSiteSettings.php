<?php

declare(strict_types=1);

namespace Stewart\Client;

use DateTimeZone;
use Stewart\Contracts\Sun\GeoLocation;

final readonly class HaSiteSettings
{
    public function __construct(
        public DateTimeZone $timeZone,
        public ?GeoLocation $location,
    ) {}
}
