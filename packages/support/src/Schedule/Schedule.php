<?php

declare(strict_types=1);

namespace Stewart\Support\Schedule;

/** @internal */
interface Schedule
{
    public function describe(): string;

    public function isRecurring(): bool;
}
