<?php

declare(strict_types=1);

namespace Stewart\Runtime\Worker;

use Stewart\Contracts\Exception\ExceptionReason;
use Stewart\Contracts\Exception\StewartException;

/** @template TResult of object */
interface PendingRequestSubject
{
    /** @return class-string<TResult> */
    public function getResultClass(): string;

    /** @return StewartException<ExceptionReason> */
    public function createUnreachableFailure(string $detail): StewartException;
}
