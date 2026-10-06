<?php

declare(strict_types=1);

namespace Stewart\Runtime\Metrics\Exposition;

use Stewart\Contracts\Time\Instant;

final class PrometheusNumber
{
    private const float LARGEST_EXACT_INTEGER = 9_007_199_254_740_992.0;

    private const int MICROSECONDS_PER_SECOND = 1_000_000;

    public static function formatValue(float $value): string
    {
        return match (true) {
            is_nan($value) => 'NaN',
            $value === INF => '+Inf',
            $value === -INF => '-Inf',
            floor($value) === $value && abs($value) < self::LARGEST_EXACT_INTEGER => \sprintf('%d', $value),
            default => (string) $value,
        };
    }

    public static function convertToEpochSeconds(Instant $instant): float
    {
        return $instant->toEpochMicroseconds() / self::MICROSECONDS_PER_SECOND;
    }
}
