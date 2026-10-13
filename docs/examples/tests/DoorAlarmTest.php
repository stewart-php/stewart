<?php

declare(strict_types=1);

namespace App\Tests;

use App\DoorAlarm;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Time\Duration;
use Stewart\Testing\App\AppHarness;

final class DoorAlarmTest extends TestCase
{
    public function testOpenDoorIsReportedAfterFiveMinutes(): void
    {
        $harness = new AppHarness();
        $harness->ha->seedState('binary_sensor.front_door', 'off');
        $harness->startApp(DoorAlarm::class, ['door' => 'binary_sensor.front_door']);

        $harness->ha->pushState('binary_sensor.front_door', 'on');
        $harness->advanceTime(Duration::minutes(5));

        self::assertSame('notify.notify', $harness->ha->getLastCall()->getServiceName());
    }
}
