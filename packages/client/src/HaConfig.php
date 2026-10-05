<?php

declare(strict_types=1);

namespace Stewart\Client;

use DateTimeZone;
use Exception;
use Stewart\Contracts\Exception\SunException;
use Stewart\Contracts\Sun\GeoLocation;

final readonly class HaConfig
{
    public function __construct(
        public ?string $timeZoneName = null,
        public ?DateTimeZone $timeZone = null,
        public ?HaCoreState $coreState = null,
        public ?GeoLocation $location = null,
    ) {}

    /** @param array<array-key, mixed> $result */
    public static function fromGetConfigResult(array $result): self
    {
        $timeZoneName = $result['time_zone'] ?? null;
        $coreState = \is_string($result['state'] ?? null) ? HaCoreState::tryFrom($result['state']) : null;
        $location = self::parseLocation($result);

        if (!\is_string($timeZoneName) || $timeZoneName === '') {
            return new self(coreState: $coreState, location: $location);
        }

        return new self($timeZoneName, self::parseTimeZone($timeZoneName), $coreState, $location);
    }

    private static function parseTimeZone(string $name): ?DateTimeZone
    {
        try {
            return new DateTimeZone($name);
        } catch (Exception) {
            return null;
        }
    }

    /** @param array<array-key, mixed> $result */
    private static function parseLocation(array $result): ?GeoLocation
    {
        $latitude = $result['latitude'] ?? null;
        $longitude = $result['longitude'] ?? null;
        $elevation = $result['elevation'] ?? 0;

        if (!\is_int($latitude) && !\is_float($latitude) || !\is_int($longitude) && !\is_float($longitude)) {
            return null;
        }

        try {
            return new GeoLocation($latitude, $longitude, \is_int($elevation) || \is_float($elevation) ? $elevation : 0.0);
        } catch (SunException) {
            return null;
        }
    }
}
