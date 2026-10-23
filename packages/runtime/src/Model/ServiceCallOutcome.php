<?php

declare(strict_types=1);

namespace Stewart\Runtime\Model;

use Stewart\Contracts\Exception\ActionCallError;
use Stewart\Contracts\Exception\EventFireError;
use Stewart\Contracts\Exception\ExceptionReason;
use Stewart\Contracts\Exception\RegistryEditError;

enum ServiceCallOutcome: string
{
    case Succeeded = 'succeeded';
    case Rejected = 'rejected';
    case Unreachable = 'unreachable';
    case TimedOut = 'timed_out';
    case Refused = 'refused';

    public static function failedWith(ExceptionReason $reason): self
    {
        return match ($reason) {
            ActionCallError::Unreachable, EventFireError::Unreachable, RegistryEditError::Unreachable => self::Unreachable,
            ActionCallError::TimedOut, EventFireError::TimedOut, RegistryEditError::TimedOut => self::TimedOut,
            ActionCallError::Overloaded, EventFireError::Overloaded, RegistryEditError::Overloaded => self::Refused,
            default => self::Rejected,
        };
    }

    public function isFailure(): bool
    {
        return $this !== self::Succeeded;
    }
}
