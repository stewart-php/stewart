<?php

declare(strict_types=1);

namespace Stewart\Runtime\Schedule;

use Stewart\Contracts\Time\Duration;
use Stewart\Contracts\Time\Instant;
use Throwable;

interface Trigger
{
    /** @throws Throwable */
    public function occursAtAll(): bool;

    /** @throws Throwable */
    public function start(): bool;

    public function dueAt(): ?Instant;

    public function isDue(): bool;

    public function delayUntilDue(): ?Duration;

    /** @throws Throwable */
    public function advance(): int;

    public function isRecurring(): bool;

    public function describe(): string;
}
