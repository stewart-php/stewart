<?php

declare(strict_types=1);

namespace Stewart\Contracts\Exception;

/** @extends StewartException<TimeError> */
final class TimeException extends StewartException
{
    public static function durationNegative(int $microseconds): self
    {
        return self::createForReason(TimeError::DurationNegative, ['microseconds' => $microseconds]);
    }

    public static function durationNotFinite(float $amount): self
    {
        return self::createForReason(TimeError::DurationNotFinite, ['amount' => var_export($amount, true)]);
    }

    public static function durationUnparsable(string $text): self
    {
        return self::createForReason(TimeError::DurationUnparsable, ['text' => $text]);
    }

    public static function instantUnparsable(string $text): self
    {
        return self::createForReason(TimeError::InstantUnparsable, ['text' => $text]);
    }

    public static function durationTooLarge(): self
    {
        return self::createForReason(TimeError::DurationTooLarge);
    }

    public static function durationNotPositive(string $usage): self
    {
        return self::createForReason(TimeError::DurationNotPositive, ['usage' => $usage]);
    }
}
