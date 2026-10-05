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
        return self::createForReason(TriggerError::ConfigUnencodable, ['path' => $path]);
    }

    public static function listEmpty(): self
    {
        return self::createForReason(TriggerError::ListEmpty);
    }

    public static function timeInvalid(string $time): self
    {
        return self::createForReason(TriggerError::TimeInvalid, ['time' => $time]);
    }

    public static function timePatternEmpty(): self
    {
        return self::createForReason(TriggerError::TimePatternEmpty);
    }

    public static function templateEmpty(): self
    {
        return self::createForReason(TriggerError::TemplateEmpty);
    }

    public static function zoneInvalid(string $zone): self
    {
        return self::createForReason(TriggerError::ZoneInvalid, ['zone' => $zone]);
    }

    public static function variablesNotMap(): self
    {
        return self::createForReason(TriggerError::VariablesNotMap);
    }
}
