<?php

declare(strict_types=1);

namespace Stewart\Contracts\Tests\Unit\Sun;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Sun\SunDirection;
use Stewart\Contracts\Sun\SunEvent;

#[CoversClass(SunEvent::class)]
final class SunEventTest extends TestCase
{
    public function testSolarNoonCrossesNoElevation(): void
    {
        self::assertNull(SunEvent::SolarNoon->findElevationCrossing());
    }

    public function testSunriseRisesThroughHorizon(): void
    {
        $crossing = SunEvent::Sunrise->findElevationCrossing();

        self::assertNotNull($crossing);
        self::assertSame(-SunEvent::SUN_RADIUS_DEGREES, $crossing->elevationDegrees);
        self::assertSame(SunDirection::Rising, $crossing->direction);
    }

    public function testEventsBeforeNoonRiseAndAfterNoonSet(): void
    {
        $direction = SunDirection::Rising;

        foreach (SunEvent::cases() as $event) {
            if ($event === SunEvent::SolarNoon) {
                $direction = SunDirection::Setting;

                continue;
            }

            self::assertSame($direction, $event->findElevationCrossing()?->direction, $event->name);
        }
    }

    public function testMorningCrossingsClimbInCaseOrder(): void
    {
        $previous = -90.0;

        foreach (SunEvent::cases() as $event) {
            $crossing = $event->findElevationCrossing();

            if ($crossing === null) {
                break;
            }

            self::assertGreaterThanOrEqual($previous, $crossing->elevationDegrees, $event->name);
            $previous = $crossing->elevationDegrees;
        }
    }

    public function testGoldenHourEndsAtSixDegrees(): void
    {
        self::assertSame(6.0, SunEvent::GoldenHourMorningEnd->findElevationCrossing()?->elevationDegrees);
        self::assertSame(6.0, SunEvent::GoldenHourEveningStart->findElevationCrossing()?->elevationDegrees);
    }

    public function testDescriptionIsReadable(): void
    {
        self::assertSame('golden hour evening start', SunEvent::GoldenHourEveningStart->describe());
    }
}
