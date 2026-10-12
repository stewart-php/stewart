<?php

declare(strict_types=1);

namespace Stewart\Runtime\Ipc\Message;

use Stewart\Contracts\Exception\ExceptionReason;
use Stewart\Contracts\Exception\StewartException;

interface RefusableHaCallRequest extends HaCallRequest
{
    /** @return StewartException<ExceptionReason> */
    public function createOverloadedFailure(string $detail): StewartException;
}
