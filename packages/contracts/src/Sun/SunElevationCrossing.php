<?php

declare(strict_types=1);

namespace Stewart\Contracts\Sun;

final readonly class SunElevationCrossing
{
    public function __construct(
        public float $elevationDegrees,
        public SunDirection $direction,
    ) {}
}
