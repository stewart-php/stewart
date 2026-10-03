<?php

declare(strict_types=1);

namespace Stewart\Runtime\Model;

use Stewart\Contracts\Exception\ServiceCallError;

enum ServiceCallOutcome: string
{
    case Succeeded = 'succeeded';
    case Rejected = 'rejected';
    case Unreachable = 'unreachable';
    case TimedOut = 'timed_out';
    case Refused = 'refused';

    public static function failedWith(ServiceCallError $reason): self
    {
        return match ($reason) {
            ServiceCallError::Rejected => self::Rejected,
            ServiceCallError::Unreachable => self::Unreachable,
            ServiceCallError::TimedOut => self::TimedOut,
            ServiceCallError::Overloaded => self::Refused,
        };
    }

    public function isFailure(): bool
    {
        return $this !== self::Succeeded;
    }
}
