<?php

declare(strict_types=1);

namespace Stewart\Sun;

/** @internal */
final readonly class SolarCoordinates
{
    public function __construct(
        public float $declinationDegrees,
        public float $equationOfTimeMinutes,
    ) {}
}
