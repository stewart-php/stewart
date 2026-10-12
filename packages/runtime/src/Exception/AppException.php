<?php

declare(strict_types=1);

namespace Stewart\Runtime\Exception;

use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Exception\StewartException;

/** @extends StewartException<AppError> */
final class AppException extends StewartException
{
    public static function unknown(AppId $appId): self
    {
        return self::createForReason(AppError::Unknown, appId: $appId->value);
    }
}
