<?php

declare(strict_types=1);

namespace Stewart\Contracts\Exception;

enum TimeError: string implements ExceptionReason
{
    case DurationNegative = 'duration_negative';
    case DurationNotFinite = 'duration_not_finite';
    case DurationNotPositive = 'duration_not_positive';
    case DurationTooLarge = 'duration_too_large';
    case DurationUnparsable = 'duration_unparsable';
    case InstantUnparsable = 'instant_unparsable';

    public function messageTemplate(): string
    {
        return match ($this) {
            self::DurationNegative => 'Duration {microseconds}us is negative.',
            self::DurationNotFinite => 'Duration {amount} is not a finite number.',
            self::DurationNotPositive => 'The duration for {usage} must be at least 1ms.',
            self::DurationTooLarge => 'Duration exceeds the 292-year maximum.',
            self::DurationUnparsable => 'Duration "{text}" is invalid; expected a number and one unit, such as 500ms, 5s, 2m or 1h.',
            self::InstantUnparsable => 'Instant "{text}" is not ISO-8601.',
        };
    }
}
