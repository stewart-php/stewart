<?php

declare(strict_types=1);

namespace Stewart\Contracts\Exception;

enum EventFireError: string implements ExceptionReason
{
    case TypeInvalid = 'type_invalid';
    case DataInvalid = 'data_invalid';
    case DataNotKeyed = 'data_not_keyed';
    case Rejected = 'rejected';
    case Unreachable = 'unreachable';
    case TimedOut = 'timed_out';
    case Overloaded = 'overloaded';

    public function messageTemplate(): string
    {
        return match ($this) {
            self::TypeInvalid => 'An event type must be 1 to 64 characters, got "{eventType}".',
            self::DataInvalid => 'Data of event {eventType} at {path} is {actualType}; expected null, a finite scalar or an array of those.',
            self::DataNotKeyed => 'Data of event {eventType} must be keyed by name, got a list.',
            self::Rejected => 'Event {eventType} was rejected by Home Assistant: {detail}',
            self::Unreachable => 'Event {eventType} could not reach Home Assistant: {detail}',
            self::TimedOut => 'Event {eventType} timed out: {detail}',
            self::Overloaded => 'Event {eventType} was refused: {detail}',
        };
    }
}
