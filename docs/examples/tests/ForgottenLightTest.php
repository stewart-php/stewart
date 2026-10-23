<?php

declare(strict_types=1);

namespace App\Tests;

use App\ForgottenLight;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Time\Duration;
use Stewart\Testing\App\AppHarness;

final class ForgottenLightTest extends TestCase
{
    private AppHarness $harness;

    protected function setUp(): void
    {
        $this->harness = new AppHarness();
        $this->harness->ha->seedState('light.hall', 'on');
        $this->harness->ha->seedHistoricalState('binary_sensor.hall_motion', 'off', $this->harness->clock->getNow()->minus(Duration::hours(1)));
        $this->harness->startApp(ForgottenLight::class);
    }

    public function testALightWithoutMotionForHalfAnHourTurnsOff(): void
    {
        $this->harness->advanceTime(Duration::minutes(15));

        self::assertSame('light.turn_off', $this->harness->ha->getLastCall()->getActionName());
    }

    public function testRecentMotionKeepsTheLightOn(): void
    {
        $this->harness->advanceTime(Duration::minutes(10));
        $this->harness->ha->pushState('binary_sensor.hall_motion', 'on');
        $this->harness->ha->pushState('binary_sensor.hall_motion', 'off');
        $this->harness->advanceTime(Duration::minutes(5));

        self::assertTrue($this->harness->ha->calls->isEmpty());
    }
}
