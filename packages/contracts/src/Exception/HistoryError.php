<?php

declare(strict_types=1);

namespace Stewart\Contracts\Exception;

enum HistoryError: string implements ExceptionReason
{
    case WindowInvalid = 'window_invalid';
    case RecorderUnavailable = 'recorder_unavailable';
    case Rejected = 'rejected';
    case Unreachable = 'unreachable';
    case TimedOut = 'timed_out';

    public function messageTemplate(): string
    {
        return match ($this) {
            self::WindowInvalid => 'A history window must end after it starts, got {startsAt} to {endsAt}.',
            self::RecorderUnavailable => 'History of {entityId} is unavailable because Home Assistant has no history integration loaded.',
            self::Rejected => 'History of {entityId} was rejected by Home Assistant: {detail}',
            self::Unreachable => 'History of {entityId} could not reach Home Assistant: {detail}',
            self::TimedOut => 'History of {entityId} timed out: {detail}',
        };
    }
}
