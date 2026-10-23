<?php

declare(strict_types=1);

namespace Automations\Tests;

use Automations\DoorAlarm;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Time\Duration;
use Stewart\Testing\App\AppHarness;

final class DoorAlarmClosedInTimeTest extends TestCase
{
    public function testDoorClosedWithinFiveMinutesIsNotReported(): void
    {
        $harness = new AppHarness();
        $harness->ha->seedState('binary_sensor.front_door', 'off');
        $harness->startApp(DoorAlarm::class, ['door' => 'binary_sensor.front_door']);

        $harness->ha->pushState('binary_sensor.front_door', 'on');
        $harness->advanceTime(Duration::minutes(4));
        $harness->ha->pushState('binary_sensor.front_door', 'off');
        $harness->advanceTime(Duration::minutes(5));

        self::assertTrue($harness->ha->calls->isEmpty());
    }
}
