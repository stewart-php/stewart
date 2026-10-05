<?php

declare(strict_types=1);

namespace Stewart\Contracts\Tests\Unit\Sun;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Exception\SunError;
use Stewart\Contracts\Exception\SunException;
use Stewart\Contracts\Sun\GeoLocation;
use Stewart\Testing\Exception\AssertsReason;

#[CoversClass(GeoLocation::class)]
#[CoversClass(SunException::class)]
final class GeoLocationTest extends TestCase
{
    use AssertsReason;

    public function testItKeepsCoordinatesAndElevation(): void
    {
        $location = new GeoLocation(47.4979, 19.0402, 96.0);

        self::assertSame(47.4979, $location->latitude);
        self::assertSame(19.0402, $location->longitude);
        self::assertSame(96.0, $location->elevationMeters);
    }

    public function testPolesAndDatelineAreAccepted(): void
    {
        $location = new GeoLocation(-90.0, 180.0);

        self::assertSame(-90.0, $location->latitude);
        self::assertSame(0.0, $location->elevationMeters);
    }

    /** @return iterable<string, array{float, float}> */
    public static function provideOutOfRangeCoordinates(): iterable
    {
        yield 'latitude above' => [90.1, 0.0];
        yield 'latitude below' => [-90.1, 0.0];
        yield 'longitude above' => [0.0, 180.1];
        yield 'longitude below' => [0.0, -180.1];
    }

    #[DataProvider('provideOutOfRangeCoordinates')]
    public function testOutOfRangeCoordinateIsRejected(float $latitude, float $longitude): void
    {
        $this->assertThrowsReason(SunError::CoordinateOutOfRange, static fn(): GeoLocation => new GeoLocation($latitude, $longitude));
    }
}
