<?php

declare(strict_types=1);

namespace Stewart\Contracts\Exception;

/** @extends StewartException<SelectorError> */
final class SelectorException extends StewartException
{
    public static function empty(string $selectorKind): self
    {
        return self::createForReason(SelectorError::Empty, ['selectorKind' => $selectorKind]);
    }

    public static function regexInvalid(string $pattern, string $compileError): self
    {
        return self::createForReason(SelectorError::RegexInvalid, ['pattern' => $pattern, 'compileError' => $compileError]);
    }

    public static function mqttFilterInvalid(string $filter): self
    {
        return self::createForReason(SelectorError::MqttFilterInvalid, ['filter' => $filter]);
    }
}
