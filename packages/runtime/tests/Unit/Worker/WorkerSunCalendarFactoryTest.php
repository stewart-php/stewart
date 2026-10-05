<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Worker;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Sun\GeoLocation;
use Stewart\Runtime\Tests\Fixtures\Ipc\TestBootstrap;
use Stewart\Runtime\Worker\WorkerSunCalendarFactory;
use Stewart\Sun\LocatedSunCalendar;
use Stewart\Sun\NoaaSolarCalculator;
use Stewart\Sun\UnlocatedSunCalendar;
use Stewart\Testing\Time\VirtualClock;

#[CoversClass(WorkerSunCalendarFactory::class)]
final class WorkerSunCalendarFactoryTest extends TestCase
{
    public function testKnownLocationGivesLocatedCalendar(): void
    {
        $location = new GeoLocation(47.4979, 19.0402);
        $factory = new WorkerSunCalendarFactory(TestBootstrap::createForApps([], location: $location), new VirtualClock(), new NoaaSolarCalculator());

        $calendar = $factory->createSunCalendar();

        self::assertInstanceOf(LocatedSunCalendar::class, $calendar);
        self::assertSame($location, $calendar->getLocation());
    }

    public function testMissingLocationGivesUnlocatedCalendar(): void
    {
        $factory = new WorkerSunCalendarFactory(TestBootstrap::createForApps([]), new VirtualClock(), new NoaaSolarCalculator());

        self::assertInstanceOf(UnlocatedSunCalendar::class, $factory->createSunCalendar());
    }
}
