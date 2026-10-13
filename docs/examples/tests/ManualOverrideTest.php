<?php

declare(strict_types=1);

namespace App\Tests;

use App\ManualOverride;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\State\EventContext;
use Stewart\Contracts\Time\Duration;
use Stewart\Testing\App\AppHarness;

final class ManualOverrideTest extends TestCase
{
    private AppHarness $harness;

    protected function setUp(): void
    {
        $this->harness = new AppHarness();
        $this->harness->ha->seedState('light.hall', 'off');
        $this->harness->ha->seedState('binary_sensor.hall_motion', 'off');
        $this->harness->startApp(ManualOverride::class);
    }

    public function testMotionTurnsTheLightOn(): void
    {
        $this->harness->ha->pushState('binary_sensor.hall_motion', 'on');

        self::assertSame('light.turn_on', $this->harness->ha->getLastCall()->getServiceName());
    }

    public function testAChangeByHandPausesTheAppForAnHour(): void
    {
        $this->harness->ha->pushState('light.hall', 'on', context: new EventContext('wall-switch', userId: 'resident'));
        $this->harness->ha->pushState('binary_sensor.hall_motion', 'on');

        self::assertTrue($this->harness->ha->calls->isEmpty());

        $this->harness->advanceTime(Duration::hours(1));
        $this->harness->ha->pushState('binary_sensor.hall_motion', 'off');
        $this->harness->ha->pushState('binary_sensor.hall_motion', 'on');

        self::assertSame('light.turn_on', $this->harness->ha->getLastCall()->getServiceName());
    }
}
