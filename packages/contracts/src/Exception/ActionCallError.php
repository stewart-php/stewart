<?php

declare(strict_types=1);

namespace Stewart\Contracts\Exception;

enum ActionCallError: string implements ExceptionReason
{
    case Rejected = 'rejected';
    case Unreachable = 'unreachable';
    case TimedOut = 'timed_out';

    case Overloaded = 'overloaded';

    case ActionNameInvalid = 'action_name_invalid';

    public function messageTemplate(): string
    {
        return match ($this) {
            self::Rejected => '{domain}.{action} was rejected by Home Assistant: {detail}',
            self::Unreachable => '{domain}.{action} could not reach Home Assistant: {detail}',
            self::TimedOut => '{domain}.{action} timed out: {detail}',
            self::Overloaded => '{domain}.{action} was refused: {detail}',
            self::ActionNameInvalid => '"{action}" is not an action name; write it as domain.action, such as light.turn_on.',
        };
    }
}
