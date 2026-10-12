<?php

declare(strict_types=1);

namespace Stewart\Contracts\Exception;

/** @extends StewartException<TimeError> */
final class TimeException extends StewartException
{
    public static function durationNotFinite(float $amount): self
    {
        return self::createForReason(TimeError::DurationNotFinite, amount: var_export($amount, true));
    }
}
