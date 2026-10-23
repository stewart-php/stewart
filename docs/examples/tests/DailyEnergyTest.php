<?php

declare(strict_types=1);

namespace Automations\Tests;

use Automations\DailyEnergy;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Time\Duration;
use Stewart\Testing\App\AppHarness;

final class DailyEnergyTest extends TestCase
{
    public function testTheSummaryAddsUpTheWeek(): void
    {
        $harness = new AppHarness();
        $harness->stores->openAppStore(new AppId('daily-energy'))->set('day:2025-12-31', 9.5);
        $harness->ha->seedState('sensor.energy_today', '7.4');
        $harness->startApp(DailyEnergy::class);

        $harness->advanceTime(Duration::minutes(23 * 60 + 55));

        self::assertSame('Today 7.4 kWh, last 7 days 16.9 kWh.', $harness->ha->getLastCall()->data['message'] ?? null);
        self::assertSame(7.4, $harness->stores->openAppStore(new AppId('daily-energy'))->getFloat('day:2026-01-01'));
    }
}
