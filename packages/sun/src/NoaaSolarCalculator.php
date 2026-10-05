<?php

declare(strict_types=1);

namespace Stewart\Sun;

use DateTimeImmutable;
use Stewart\Contracts\Sun\GeoLocation;
use Stewart\Contracts\Sun\SunDirection;
use Stewart\Contracts\Sun\SunElevationCrossing;
use Stewart\Contracts\Sun\SunEvent;
use Stewart\Contracts\Sun\SunPosition;
use Stewart\Contracts\Time\Instant;

final readonly class NoaaSolarCalculator
{
    private const float JULIAN_DAY_AT_UNIX_EPOCH = 2440587.5;
    private const float JULIAN_DAY_AT_J2000 = 2451545.0;
    private const float DAYS_PER_JULIAN_CENTURY = 36525.0;
    private const float SECONDS_PER_DAY = 86400.0;
    private const float MINUTES_PER_DAY = 1440.0;
    private const float MINUTES_PER_LONGITUDE_DEGREE = 4.0;
    private const float NOON_MINUTES = 720.0;
    private const float EARTH_RADIUS_METERS = 6356900.0;
    // Hour angles are undefined at the poles themselves.
    private const float LATITUDE_LIMIT_DEGREES = 89.8;
    private const int REFINEMENT_PASSES = 3;

    public function findEventOn(DateTimeImmutable $localDate, GeoLocation $location, SunEvent $event): ?Instant
    {
        $crossing = $event->findElevationCrossing();

        if ($crossing === null) {
            return $this->findSolarNoonOn($localDate, $location);
        }

        return $this->findCrossingOn($localDate, $location, $crossing);
    }

    public function findSolarNoonOn(DateTimeImmutable $localDate, GeoLocation $location): Instant
    {
        $utcMidnight = $this->findUtcMidnightOfTransit($localDate, $location);
        $transit = $utcMidnight + 0.5;

        for ($pass = 0; $pass < self::REFINEMENT_PASSES; ++$pass) {
            $transit = $this->computeTransitAt($utcMidnight, $location, $this->computeCoordinatesAt($transit));
        }

        return $this->convertToInstant($transit);
    }

    public function findCrossingOn(DateTimeImmutable $localDate, GeoLocation $location, SunElevationCrossing $crossing): ?Instant
    {
        $utcMidnight = $this->findUtcMidnightOfTransit($localDate, $location);
        $targetElevation = $this->computeGeometricElevationOf($crossing, $location);
        $sign = $crossing->direction === SunDirection::Rising ? -1.0 : 1.0;
        $estimate = $utcMidnight + 0.5;

        for ($pass = 0; $pass < self::REFINEMENT_PASSES; ++$pass) {
            $coordinates = $this->computeCoordinatesAt($estimate);
            $hourAngle = $this->computeHourAngleDegrees($location, $coordinates, $targetElevation);

            if ($hourAngle === null) {
                return null;
            }

            $estimate = $this->computeTransitAt($utcMidnight, $location, $coordinates) + $sign * $hourAngle / 360.0;
        }

        return $this->convertToInstant($estimate);
    }

    public function computePositionAt(Instant $moment, GeoLocation $location): SunPosition
    {
        $geometric = $this->computeGeometricPositionAt($moment, $location);

        return new SunPosition(
            $geometric->azimuthDegrees,
            $geometric->elevationDegrees + $this->computeRefractionDegrees($geometric->elevationDegrees),
        );
    }

    public function isSunUpAt(Instant $moment, GeoLocation $location): bool
    {
        $sunrise = SunEvent::Sunrise->findElevationCrossing();
        \assert($sunrise !== null);

        return $this->computeGeometricPositionAt($moment, $location)->elevationDegrees > $this->computeGeometricElevationOf($sunrise, $location);
    }

    private function computeGeometricPositionAt(Instant $moment, GeoLocation $location): SunPosition
    {
        $julianDay = $this->convertToJulianDay($moment);
        $coordinates = $this->computeCoordinatesAt($julianDay);
        $latitude = deg2rad($this->clampLatitude($location->latitude));
        $declination = deg2rad($coordinates->declinationDegrees);

        $utcMinutes = ($julianDay + 0.5 - floor($julianDay + 0.5)) * self::MINUTES_PER_DAY;
        $trueSolarMinutes = $this->wrap($utcMinutes + $coordinates->equationOfTimeMinutes + self::MINUTES_PER_LONGITUDE_DEGREE * $location->longitude, self::MINUTES_PER_DAY);
        $hourAngle = $trueSolarMinutes / self::MINUTES_PER_LONGITUDE_DEGREE - 180.0;

        $cosZenith = sin($latitude) * sin($declination) + cos($latitude) * cos($declination) * cos(deg2rad($hourAngle));
        $zenith = acos($this->clampUnit($cosZenith));
        $cosAzimuth = (sin($latitude) * cos($zenith) - sin($declination)) / (cos($latitude) * sin($zenith));
        $azimuth = rad2deg(acos($this->clampUnit($cosAzimuth)));

        return new SunPosition(
            $hourAngle > 0 ? $this->wrap($azimuth + 180.0, 360.0) : $this->wrap(540.0 - $azimuth, 360.0),
            90.0 - rad2deg($zenith),
        );
    }

    private function findUtcMidnightOfTransit(DateTimeImmutable $localDate, GeoLocation $location): float
    {
        $localNoon = $this->convertToJulianDay(Instant::fromDateTime($localDate->setTime(12, 0)));
        $utcMidnight = floor($localNoon + 0.5) - 0.5;
        $transit = $this->computeTransitAt($utcMidnight, $location, $this->computeCoordinatesAt($localNoon));

        return $utcMidnight + round($localNoon - $transit);
    }

    private function computeTransitAt(float $utcMidnight, GeoLocation $location, SolarCoordinates $coordinates): float
    {
        $minutes = self::NOON_MINUTES - self::MINUTES_PER_LONGITUDE_DEGREE * $location->longitude - $coordinates->equationOfTimeMinutes;

        return $utcMidnight + $minutes / self::MINUTES_PER_DAY;
    }

    private function computeHourAngleDegrees(GeoLocation $location, SolarCoordinates $coordinates, float $elevationDegrees): ?float
    {
        $latitude = deg2rad($this->clampLatitude($location->latitude));
        $declination = deg2rad($coordinates->declinationDegrees);

        $cosHourAngle = (sin(deg2rad($elevationDegrees)) - sin($latitude) * sin($declination)) / (cos($latitude) * cos($declination));

        return abs($cosHourAngle) > 1.0 ? null : rad2deg(acos($cosHourAngle));
    }

    private function computeCoordinatesAt(float $julianDay): SolarCoordinates
    {
        $century = ($julianDay - self::JULIAN_DAY_AT_J2000) / self::DAYS_PER_JULIAN_CENTURY;

        $meanLongitude = deg2rad($this->wrap(280.46646 + $century * (36000.76983 + $century * 0.0003032), 360.0));
        $meanAnomaly = deg2rad(357.52911 + $century * (35999.05029 - 0.0001537 * $century));
        $eccentricity = 0.016708634 - $century * (0.000042037 + 0.0000001267 * $century);

        $equationOfCenter = sin($meanAnomaly) * (1.914602 - $century * (0.004817 + 0.000014 * $century))
            + sin(2 * $meanAnomaly) * (0.019993 - 0.000101 * $century)
            + sin(3 * $meanAnomaly) * 0.000289;
        $nutation = deg2rad(125.04 - 1934.136 * $century);
        $apparentLongitude = deg2rad(rad2deg($meanLongitude) + $equationOfCenter - 0.00569 - 0.00478 * sin($nutation));

        $meanObliquity = 23 + (26 + (21.448 - $century * (46.815 + $century * (0.00059 - $century * 0.001813))) / 60) / 60;
        $obliquity = deg2rad($meanObliquity + 0.00256 * cos($nutation));

        $obliquityFactor = tan($obliquity / 2) ** 2;
        $equationOfTime = $obliquityFactor * sin(2 * $meanLongitude)
            - 2 * $eccentricity * sin($meanAnomaly)
            + 4 * $eccentricity * $obliquityFactor * sin($meanAnomaly) * cos(2 * $meanLongitude)
            - 0.5 * $obliquityFactor * $obliquityFactor * sin(4 * $meanLongitude)
            - 1.25 * $eccentricity * $eccentricity * sin(2 * $meanAnomaly);

        return new SolarCoordinates(
            rad2deg(asin(sin($obliquity) * sin($apparentLongitude))),
            self::MINUTES_PER_LONGITUDE_DEGREE * rad2deg($equationOfTime),
        );
    }

    private function computeGeometricElevationOf(SunElevationCrossing $crossing, GeoLocation $location): float
    {
        $seenElevation = $crossing->elevationDegrees - $this->computeHorizonDipDegrees($location);

        return $seenElevation - $this->computeRefractionDegrees($seenElevation);
    }

    // Astral's piecewise refraction model, so times agree with Home Assistant's sun integration.
    private function computeRefractionDegrees(float $elevationDegrees): float
    {
        if ($elevationDegrees >= 85.0) {
            return 0.0;
        }

        $tangent = tan(deg2rad($elevationDegrees));

        $arcSeconds = match (true) {
            $elevationDegrees > 5.0 => 58.1 / $tangent - 0.07 / $tangent ** 3 + 0.000086 / $tangent ** 5,
            $elevationDegrees > -0.575 => 1735.0 + $elevationDegrees * (-518.2 + $elevationDegrees * (103.4 + $elevationDegrees * (-12.79 + $elevationDegrees * 0.711))),
            default => -20.774 / $tangent,
        };

        return $arcSeconds / 3600.0;
    }

    private function computeHorizonDipDegrees(GeoLocation $location): float
    {
        if ($location->elevationMeters <= 0.0) {
            return 0.0;
        }

        return rad2deg(acos(self::EARTH_RADIUS_METERS / (self::EARTH_RADIUS_METERS + $location->elevationMeters)));
    }

    private function convertToJulianDay(Instant $moment): float
    {
        return $moment->toEpochMicroseconds() / 1_000_000 / self::SECONDS_PER_DAY + self::JULIAN_DAY_AT_UNIX_EPOCH;
    }

    private function convertToInstant(float $julianDay): Instant
    {
        $epochSeconds = (int) round(($julianDay - self::JULIAN_DAY_AT_UNIX_EPOCH) * self::SECONDS_PER_DAY);

        return Instant::fromEpochMicroseconds($epochSeconds * 1_000_000);
    }

    private function clampLatitude(float $latitude): float
    {
        return max(-self::LATITUDE_LIMIT_DEGREES, min(self::LATITUDE_LIMIT_DEGREES, $latitude));
    }

    private function clampUnit(float $value): float
    {
        return max(-1.0, min(1.0, $value));
    }

    private function wrap(float $value, float $period): float
    {
        return $value - $period * floor($value / $period);
    }
}
