<?php

declare(strict_types=1);

namespace Stewart\Runtime\Time;

use DateTimeZone;

interface ProcessTimeZone
{
    public function useAsProcessDefault(DateTimeZone $zone): void;
}
