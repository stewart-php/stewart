<?php

declare(strict_types=1);

namespace Stewart\Runtime\Worker;

use Throwable;

/** @template TResult of object */
interface PendingRequestSubject
{
    /** @return class-string<TResult> */
    public function getResultClass(): string;

    public function createUnreachableFailure(string $detail): Throwable;
}
