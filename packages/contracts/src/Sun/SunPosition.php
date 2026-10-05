<?php

declare(strict_types=1);

namespace Stewart\Contracts\Sun;

final readonly class SunPosition
{
    public function __construct(
        public float $azimuthDegrees,
        public float $elevationDegrees,
    ) {}

    public function isAboveHorizon(): bool
    {
        return $this->elevationDegrees > SunEvent::HORIZON_DEGREES;
    }
}
