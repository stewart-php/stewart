<?php

declare(strict_types=1);

namespace App\Tests;

use App\BatteryAlert;
use App\Support\QuietHours;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Time\Duration;
use Stewart\Testing\App\AppHarness;

final class BatteryAlertTest extends TestCase
{
    private AppHarness $harness;

    protected function setUp(): void
    {
        $this->harness = new AppHarness();
        $this->harness->provideService(QuietHours::class, new QuietHours($this->harness->clock));
        $this->harness->ha->seedState('sensor.door_battery', '50', ['friendly_name' => 'Door battery']);
        $this->harness->startApp(BatteryAlert::class, ['threshold' => 25]);
    }

    public function testALowBatteryIsReportedDuringTheDay(): void
    {
        $this->harness->advanceTime(Duration::hours(9));
        $this->harness->ha->pushState('sensor.door_battery', '15', ['friendly_name' => 'Door battery']);

        self::assertSame(['message' => 'Door battery is at 15%.'], $this->harness->ha->getLastCall()->data);
    }

    public function testNothingIsSentAtNight(): void
    {
        $this->harness->ha->pushState('sensor.door_battery', '15', ['friendly_name' => 'Door battery']);

        self::assertTrue($this->harness->ha->calls->isEmpty());
    }
}
