<?php

declare(strict_types=1);

namespace Stewart\Contracts\Time;

interface TimerHandle
{
    public function cancel(): void;

    public function isPending(): bool;
}
