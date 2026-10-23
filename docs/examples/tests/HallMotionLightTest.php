<?php

declare(strict_types=1);

namespace Automations\Tests;

use Automations\HallMotionLight;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Time\Duration;
use Stewart\Testing\App\AppHarness;

final class HallMotionLightTest extends TestCase
{
    private AppHarness $harness;

    protected function setUp(): void
    {
        $this->harness = new AppHarness();
        $this->harness->ha->seedState('binary_sensor.hall_motion', 'off');
        $this->harness->startApp(HallMotionLight::class);
    }

    public function testMotionTurnsTheLightOn(): void
    {
        $this->harness->ha->pushState('binary_sensor.hall_motion', 'on');

        self::assertSame('light.turn_on', $this->harness->ha->getLastCall()->getActionName());
    }

    public function testTheLightTurnsOffTwoMinutesAfterTheLastMotion(): void
    {
        $this->harness->ha->pushState('binary_sensor.hall_motion', 'on');
        $this->harness->ha->pushState('binary_sensor.hall_motion', 'off');
        $this->harness->advanceTime(Duration::minutes(2));

        self::assertSame('light.turn_off', $this->harness->ha->getLastCall()->getActionName());
    }

    public function testMotionWithinTwoMinutesKeepsTheLightOn(): void
    {
        $this->harness->ha->pushState('binary_sensor.hall_motion', 'on');
        $this->harness->ha->pushState('binary_sensor.hall_motion', 'off');
        $this->harness->advanceTime(Duration::minutes(1));
        $this->harness->ha->pushState('binary_sensor.hall_motion', 'on');
        $this->harness->advanceTime(Duration::minutes(5));

        self::assertSame('light.turn_on', $this->harness->ha->getLastCall()->getActionName());
    }
}
