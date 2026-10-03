<?php

declare(strict_types=1);

namespace Stewart\Runtime\Exception;

use Stewart\Contracts\Exception\StewartException;
use Stewart\Contracts\Time\Duration;
use Stewart\Runtime\Model\WorkerId;
use Throwable;

/** @extends StewartException<BrokerError> */
final class BrokerException extends StewartException
{
    public static function noWorkersStarted(int $workers): self
    {
        return self::createForReason(BrokerError::NoWorkersStarted, ['workers' => $workers]);
    }

    public static function workerSpawnTimedOut(WorkerId $workerId, Duration $timeout, Throwable $previous): self
    {
        return self::createForReason(BrokerError::WorkerSpawnTimedOut, ['worker' => $workerId->value, 'timeout' => (string) $timeout], $previous);
    }
}
