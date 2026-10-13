<?php

declare(strict_types=1);

namespace App\Support;

use Stewart\Contracts\Time\Clock;

final readonly class QuietHours
{
    public function __construct(private Clock $clock) {}

    public function isNow(): bool
    {
        return $this->clock->isWithin('22:00', '07:00');
    }
}
