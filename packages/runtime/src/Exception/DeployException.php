<?php

declare(strict_types=1);

namespace Stewart\Runtime\Exception;

use Stewart\Contracts\Exception\StewartException;
use Stewart\Contracts\Time\Duration;
use Throwable;

/** @extends StewartException<DeployError> */
final class DeployException extends StewartException
{
    public static function commitIdInvalid(string $value): self
    {
        return self::createForReason(DeployError::CommitIdInvalid, ['value' => $value]);
    }

    public static function commandTimedOut(string $command, Duration $timeout, Throwable $previous): self
    {
        return self::createForReason(DeployError::CommandTimedOut, ['command' => $command, 'timeout' => (string) $timeout], $previous);
    }

    public static function commandFailed(string $command, int $exitCode, string $detail): self
    {
        return self::createForReason(DeployError::CommandFailed, ['command' => $command, 'exitCode' => $exitCode, 'detail' => $detail]);
    }

    public static function prepareOutputUnexpected(string $output): self
    {
        return self::createForReason(DeployError::PrepareOutputUnexpected, ['output' => $output]);
    }
}
