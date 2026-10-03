<?php

declare(strict_types=1);

namespace Stewart\Contracts\Schedule;

use Stewart\Contracts\Time\Instant;

interface ScheduledTask
{
    public function getId(): string;

    public function cancel(): void;

    public function isActive(): bool;

    public function getNextRunAt(): ?Instant;
}
