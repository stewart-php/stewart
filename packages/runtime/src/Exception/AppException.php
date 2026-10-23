<?php

declare(strict_types=1);

namespace Stewart\Runtime\Exception;

use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Exception\StewartException;

/** @extends StewartException<AppError> */
final class AppException extends StewartException
{
    public static function unknown(AppId $appId, ?string $suggestion = null): self
    {
        return self::createForReasonWithAppendedText(
            AppError::Unknown,
            ['appId' => $appId->value, 'suggestion' => $suggestion],
            $suggestion === null ? null : \sprintf('Did you mean "%s"?', $suggestion),
        );
    }

    public static function instanceClassMissing(AppId $appId, string $className, ?string $suggestion): self
    {
        return self::createForReasonWithAppendedText(
            AppError::InstanceClassMissing,
            ['appId' => $appId->value, 'className' => $className, 'suggestion' => $suggestion],
            $suggestion === null ? null : \sprintf('Did you mean %s?', $suggestion),
        );
    }
}
