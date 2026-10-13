<?php

declare(strict_types=1);

namespace App\Tests;

use App\WeekdayMorning;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Time\Duration;
use Stewart\Testing\App\AppHarness;

final class WeekdayMorningTest extends TestCase
{
    public function testTheCoffeeMachineStartsOnAWeekday(): void
    {
        $harness = new AppHarness(new DateTimeImmutable('2026-01-01 00:00 UTC'));
        $harness->startApp(WeekdayMorning::class);

        $harness->advanceTime(Duration::minutes(6 * 60 + 45));

        self::assertSame('switch.turn_on', $harness->ha->getLastCall()->getServiceName());
    }

    public function testTheCoffeeMachineSleepsInOnSaturday(): void
    {
        $harness = new AppHarness(new DateTimeImmutable('2026-01-03 00:00 UTC'));
        $harness->startApp(WeekdayMorning::class);

        $harness->advanceTime(Duration::hours(7));

        self::assertTrue($harness->ha->calls->isEmpty());
    }

    public function testTheCoversCloseHalfAnHourBeforeSunset(): void
    {
        $harness = new AppHarness(new DateTimeImmutable('2026-01-03 00:00 UTC'));
        $harness->startApp(WeekdayMorning::class);

        $harness->advanceTime(Duration::minutes(17 * 60 + 30));

        self::assertSame('cover.close_cover', $harness->ha->getLastCall()->getServiceName());
    }
}
