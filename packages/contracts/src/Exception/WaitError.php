<?php

declare(strict_types=1);

namespace Stewart\Contracts\Exception;

enum WaitError: string implements ExceptionReason
{
    case Abandoned = 'abandoned';

    public function messageTemplate(): string
    {
        return match ($this) {
            self::Abandoned => 'The wait ended before an event arrived, because its stream completed or its app stopped.',
        };
    }
}
