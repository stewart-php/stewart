<?php

declare(strict_types=1);

namespace Stewart\Runtime\Model;

use Stewart\Contracts\Exception\EventFireError;
use Stewart\Contracts\Exception\ServiceCallError;

enum ServiceCallOutcome: string
{
    case Succeeded = 'succeeded';
    case Rejected = 'rejected';
    case Unreachable = 'unreachable';
    case TimedOut = 'timed_out';
    case Refused = 'refused';

    public static function failedWith(ServiceCallError|EventFireError $reason): self
    {
        return match ($reason) {
            ServiceCallError::Rejected, EventFireError::Rejected, EventFireError::TypeInvalid, EventFireError::DataInvalid, EventFireError::DataNotKeyed => self::Rejected,
            ServiceCallError::Unreachable, EventFireError::Unreachable => self::Unreachable,
            ServiceCallError::TimedOut, EventFireError::TimedOut => self::TimedOut,
            ServiceCallError::Overloaded, EventFireError::Overloaded => self::Refused,
        };
    }

    public function isFailure(): bool
    {
        return $this !== self::Succeeded;
    }
}
