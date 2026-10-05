<?php

declare(strict_types=1);

namespace Stewart\Contracts\Sun;

use Stewart\Contracts\Exception\SunException;

final readonly class GeoLocation
{
    private const int LATITUDE_LIMIT = 90;
    private const int LONGITUDE_LIMIT = 180;

    /** @throws SunException */
    public function __construct(
        public float $latitude,
        public float $longitude,
        public float $elevationMeters = 0.0,
    ) {
        if (abs($latitude) > self::LATITUDE_LIMIT) {
            throw SunException::coordinateOutOfRange('latitude', $latitude, self::LATITUDE_LIMIT);
        }

        if (abs($longitude) > self::LONGITUDE_LIMIT) {
            throw SunException::coordinateOutOfRange('longitude', $longitude, self::LONGITUDE_LIMIT);
        }
    }
}
