<?php

declare(strict_types=1);

namespace App\Tests;

use App\DoorCounter;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\App\AppId;
use Stewart\Testing\App\AppHarness;

final class DoorCounterTest extends TestCase
{
    public function testEachOpeningIsCounted(): void
    {
        $harness = new AppHarness();
        $harness->ha->seedState('binary_sensor.front_door', 'off');
        $harness->startApp(DoorCounter::class);

        $harness->ha->pushState('binary_sensor.front_door', 'on');
        $harness->ha->pushState('binary_sensor.front_door', 'off');
        $harness->ha->pushState('binary_sensor.front_door', 'on');

        self::assertSame(2, $harness->stores->openAppStore(new AppId('door-counter'))->getInt('opens'));
    }
}
