<?php

declare(strict_types=1);

namespace Stewart\Contracts\Sun;

final readonly class SunPosition
{
    private const float FULL_TURN_DEGREES = 360.0;

    public function __construct(
        public float $azimuthDegrees,
        public float $elevationDegrees,
    ) {}

    public function isHigherThan(float $elevationDegrees): bool
    {
        return $this->elevationDegrees > $elevationDegrees;
    }

    public function isWithinAzimuth(float $fromDegrees, float $toDegrees): bool
    {
        $azimuth = self::normalizeAzimuth($this->azimuthDegrees);
        $from = self::normalizeAzimuth($fromDegrees);
        $to = self::normalizeAzimuth($toDegrees);

        if ($from <= $to) {
            return $azimuth >= $from && $azimuth <= $to;
        }

        return $azimuth >= $from || $azimuth <= $to;
    }

    private static function normalizeAzimuth(float $degrees): float
    {
        return $degrees - self::FULL_TURN_DEGREES * floor($degrees / self::FULL_TURN_DEGREES);
    }
}
