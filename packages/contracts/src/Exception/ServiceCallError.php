<?php

declare(strict_types=1);

namespace Stewart\Contracts\Exception;

enum ServiceCallError: string implements ExceptionReason
{
    case Rejected = 'rejected';
    case Unreachable = 'unreachable';
    case TimedOut = 'timed_out';

    case Overloaded = 'overloaded';

    public function messageTemplate(): string
    {
        return match ($this) {
            self::Rejected => '{domain}.{service} was rejected by Home Assistant: {detail}',
            self::Unreachable => '{domain}.{service} could not reach Home Assistant: {detail}',
            self::TimedOut => '{domain}.{service} timed out: {detail}',
            self::Overloaded => '{domain}.{service} was refused: {detail}',
        };
    }
}
