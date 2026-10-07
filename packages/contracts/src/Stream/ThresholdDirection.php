<?php

declare(strict_types=1);

namespace Stewart\Contracts\Stream;

/** @internal */
enum ThresholdDirection
{
    case Above;
    case Below;

    public function isBeyond(float $value, float $threshold): bool
    {
        return match ($this) {
            self::Above => $value > $threshold,
            self::Below => $value < $threshold,
        };
    }

    public function hasReturned(float $value, float $threshold, float $hysteresis): bool
    {
        return match ($this) {
            self::Above => $value <= $threshold - $hysteresis,
            self::Below => $value >= $threshold + $hysteresis,
        };
    }

    public function describeUsage(): string
    {
        return match ($this) {
            self::Above => 'whenAbove',
            self::Below => 'whenBelow',
        };
    }
}
