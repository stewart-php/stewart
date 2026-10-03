<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Schedule;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Schedule\CalendarSchedule;
use Stewart\Contracts\Schedule\IntervalSchedule;
use Stewart\Contracts\Time\Duration;
use Stewart\Runtime\Schedule\ElapsedTrigger;
use Stewart\Runtime\Schedule\TriggerFactory;
use Stewart\Runtime\Schedule\WallTrigger;
use Stewart\Testing\Time\VirtualClock;

#[CoversClass(TriggerFactory::class)]
final class TriggerFactoryTest extends TestCase
{
    public function testElapsedScheduleGetsElapsedTrigger(): void
    {
        $trigger = new TriggerFactory(new VirtualClock())->createTriggerFor(IntervalSchedule::every(Duration::seconds(10)));

        self::assertInstanceOf(ElapsedTrigger::class, $trigger);
    }

    public function testWallClockScheduleGetsWallTrigger(): void
    {
        $trigger = new TriggerFactory(new VirtualClock())->createTriggerFor(CalendarSchedule::dailyAt('07:00'));

        self::assertInstanceOf(WallTrigger::class, $trigger);
    }
}
