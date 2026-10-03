<?php

declare(strict_types=1);

namespace Stewart\Runtime\Exception;

use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Exception\StewartException;
use Stewart\Contracts\Time\Duration;
use Throwable;

/** @extends StewartException<AppError> */
final class AppException extends StewartException
{
    public static function duplicateId(AppId $appId, string $firstClass, string $secondClass): self
    {
        return self::createForReason(AppError::DuplicateId, ['appId' => $appId->value, 'firstClass' => $firstClass, 'secondClass' => $secondClass]);
    }

    public static function directoryNotAutoloadable(string $directory): self
    {
        return self::createForReason(AppError::DirectoryNotAutoloadable, ['directory' => $directory]);
    }

    public static function notAnApp(string $className, string $appInterface): self
    {
        return self::createForReason(AppError::NotAnApp, ['className' => $className, 'appInterface' => $appInterface]);
    }

    public static function notInstantiable(string $className): self
    {
        return self::createForReason(AppError::NotInstantiable, ['className' => $className]);
    }

    public static function idInvalid(string $className, string $appId): self
    {
        return self::createForReason(AppError::IdInvalid, ['className' => $className, 'appId' => $appId]);
    }

    public static function initializeTimedOut(AppId $appId, Duration $timeout, ?Throwable $previous = null): self
    {
        return self::createForReason(AppError::InitializeTimedOut, ['appId' => $appId->value, 'timeout' => (string) $timeout], $previous);
    }
}
