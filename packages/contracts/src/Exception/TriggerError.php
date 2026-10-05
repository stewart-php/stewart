<?php

declare(strict_types=1);

namespace Stewart\Contracts\Exception;

enum TriggerError: string implements ExceptionReason
{
    case ConfigInvalid = 'config_invalid';
    case ConfigUnencodable = 'config_unencodable';
    case ListEmpty = 'list_empty';
    case TimeInvalid = 'time_invalid';
    case TimePatternEmpty = 'time_pattern_empty';
    case TemplateEmpty = 'template_empty';
    case ZoneInvalid = 'zone_invalid';
    case VariablesNotMap = 'variables_not_map';

    public function messageTemplate(): string
    {
        return match ($this) {
            self::ConfigInvalid => 'A Home Assistant trigger must be a map with a non-empty "trigger" or "platform" key.',
            self::ConfigUnencodable => 'Trigger value at {path} cannot be encoded as JSON.',
            self::ListEmpty => 'A trigger subscription needs at least one trigger.',
            self::TimeInvalid => 'Trigger time "{time}" is neither HH:MM[:SS] nor an input_datetime or sensor entity.',
            self::TimePatternEmpty => 'A time pattern trigger needs hours, minutes or seconds.',
            self::TemplateEmpty => 'A template trigger needs a non-empty template.',
            self::ZoneInvalid => 'Trigger zone "{zone}" is not a zone entity.',
            self::VariablesNotMap => 'Trigger variables must be a map keyed by variable name.',
        };
    }
}
