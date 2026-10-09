<?php

declare(strict_types=1);

namespace Stewart\Contracts\Exception;

enum CommandError: string implements ExceptionReason
{
    case Rejected = 'command_rejected';

    public function messageTemplate(): string
    {
        return match ($this) {
            self::Rejected => '{reason}',
        };
    }
}
