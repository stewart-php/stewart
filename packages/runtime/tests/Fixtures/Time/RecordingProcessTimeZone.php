<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Time;

use DateTimeZone;
use Stewart\Runtime\Time\ProcessTimeZone;

final class RecordingProcessTimeZone implements ProcessTimeZone
{
    public ?DateTimeZone $adopted = null;

    public function useAsProcessDefault(DateTimeZone $zone): void
    {
        $this->adopted = $zone;
    }
}
