<?php

declare(strict_types=1);

namespace Stewart\Contracts\Exception;

enum SelectorError: string implements ExceptionReason
{
    case Empty = 'selector_empty';
    case RegexInvalid = 'regex_invalid';
    case MqttFilterInvalid = 'mqtt_filter_invalid';

    public function messageTemplate(): string
    {
        return match ($this) {
            self::Empty => '{selectorKind} cannot be empty.',
            self::RegexInvalid => 'Regular expression selector {pattern} is invalid: {compileError}.',
            self::MqttFilterInvalid => 'MQTT topic filter {filter} must use + and # only as whole levels, with # last.',
        };
    }
}
