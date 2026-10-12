<?php

declare(strict_types=1);

namespace Stewart\Contracts\Time;

use Throwable;

/** @template T */
interface PendingWait
{
    /** @param T $value */
    public function resolve(mixed $value): void;

    public function fail(Throwable $reason): void;
}
