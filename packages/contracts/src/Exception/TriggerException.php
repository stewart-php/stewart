<?php

declare(strict_types=1);

namespace Stewart\Contracts\Exception;

/** @extends StewartException<TriggerError> */
final class TriggerException extends StewartException
{
    public static function configInvalid(): self
    {
        return self::createForReason(TriggerError::ConfigInvalid);
    }

    public static function configUnencodable(string $path): self
    {
        return self::createForReason(TriggerError::ConfigUnencodable, path: $path);
    }
}
