<?php

declare(strict_types=1);

namespace App\Tests;

use App\SouthBlinds;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Time\Duration;
use Stewart\Testing\App\AppHarness;

final class SouthBlindsTest extends TestCase
{
    private AppHarness $harness;

    protected function setUp(): void
    {
        // The harness's sun stands in the south, 45° high, from 06:00 to 18:00.
        $this->harness = new AppHarness();
        $this->harness->ha->seedState('cover.south_blinds', 'open');
        $this->harness->startApp(SouthBlinds::class);
    }

    public function testTheBlindsStayOpenAtNight(): void
    {
        $this->harness->advanceTime(Duration::hours(5));

        self::assertTrue($this->harness->ha->calls->isEmpty());
    }

    public function testTheBlindsCloseWhenTheSunIsOnTheFacade(): void
    {
        $this->harness->advanceTime(Duration::hours(7));

        self::assertSame('cover.close_cover', $this->harness->ha->getLastCall()->getServiceName());
    }
}
