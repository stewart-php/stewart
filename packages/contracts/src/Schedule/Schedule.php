<?php

declare(strict_types=1);

namespace Stewart\Contracts\Schedule;

interface Schedule
{
    public function describe(): string;

    public function isRecurring(): bool;
}
