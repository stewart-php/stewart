<?php

declare(strict_types=1);

namespace App\Tests;

use App\SunBlinds;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Time\Duration;
use Stewart\Testing\App\AppHarness;

final class SunBlindsTest extends TestCase
{
    private AppHarness $harness;

    protected function setUp(): void
    {
        // The harness's sun stands in the south, 45° high, from 06:00 to 18:00.
        $this->harness = new AppHarness();
        $this->harness->ha->seedState('cover.south_blinds', 'open');
        $this->harness->startApp(SunBlinds::class);
    }

    public function testTheBlindsCloseOnAHotSunnyDay(): void
    {
        $this->harness->ha->seedState('sensor.outside_temperature', '28');
        $this->harness->advanceTime(Duration::hours(10));

        self::assertSame('cover.close_cover', $this->harness->ha->getLastCall()->getServiceName());
    }

    public function testTheBlindsStayOpenOnACoolDay(): void
    {
        $this->harness->ha->seedState('sensor.outside_temperature', '18');
        $this->harness->advanceTime(Duration::hours(10));

        self::assertTrue($this->harness->ha->calls->isEmpty());
    }

    public function testTheBlindsOpenAtSunset(): void
    {
        $this->harness->ha->seedState('sensor.outside_temperature', '18');
        $this->harness->ha->pushState('cover.south_blinds', 'closed');
        $this->harness->advanceTime(Duration::hours(18));

        self::assertSame('cover.open_cover', $this->harness->ha->getLastCall()->getServiceName());
    }
}
