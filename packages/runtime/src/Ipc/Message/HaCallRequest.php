<?php

declare(strict_types=1);

namespace Stewart\Runtime\Ipc\Message;

use Stewart\Contracts\Exception\ExceptionReason;
use Stewart\Contracts\Exception\StewartException;
use Stewart\Runtime\Model\CorrelationId;
use Stewart\Runtime\Model\ResourceScope;
use Throwable;

interface HaCallRequest
{
    public CorrelationId $correlationId { get; }

    public ResourceScope $scope { get; }

    /** @return StewartException<ExceptionReason> */
    public function createUnreachableFailure(string $detail, ?Throwable $previous = null): StewartException;
}
