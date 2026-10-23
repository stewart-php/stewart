<?php

declare(strict_types=1);

namespace Stewart\Support\Time;

/** @internal */
interface TimerHandle
{
    public function cancel(): void;

    public function isPending(): bool;
}
