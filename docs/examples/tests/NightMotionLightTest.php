<?php

declare(strict_types=1);

namespace Automations\Tests;

use Automations\NightMotionLight;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Time\Duration;
use Stewart\Testing\App\AppHarness;

final class NightMotionLightTest extends TestCase
{
    public function testLateAtNightTheLightIsDim(): void
    {
        $harness = $this->startAt('2026-01-01 23:30 UTC');

        $harness->ha->pushState('binary_sensor.hall_motion', 'on');

        self::assertSame(['brightness_pct' => 10], $harness->ha->getLastCall()->data);
    }

    public function testInTheEveningTheLightIsBright(): void
    {
        $harness = $this->startAt('2026-01-01 19:00 UTC');

        $harness->ha->pushState('binary_sensor.hall_motion', 'on');

        self::assertSame(['brightness_pct' => 100], $harness->ha->getLastCall()->data);
    }

    public function testDuringTheDayNothingHappens(): void
    {
        $harness = $this->startAt('2026-01-01 12:00 UTC');

        $harness->ha->pushState('binary_sensor.hall_motion', 'on');

        self::assertTrue($harness->ha->calls->isEmpty());
    }

    public function testTheLightTurnsOffAfterTheMotionStops(): void
    {
        $harness = $this->startAt('2026-01-01 19:00 UTC');
        $harness->ha->pushState('binary_sensor.hall_motion', 'on');
        $harness->ha->pushState('light.hall', 'on');
        $harness->ha->pushState('binary_sensor.hall_motion', 'off');

        $harness->advanceTime(Duration::minutes(3));

        self::assertSame('light.turn_off', $harness->ha->getLastCall()->getActionName());
    }

    private function startAt(string $moment): AppHarness
    {
        $harness = new AppHarness(new DateTimeImmutable($moment));
        $harness->ha->seedState('binary_sensor.hall_motion', 'off');
        $harness->ha->seedState('light.hall', 'off');
        $harness->startApp(NightMotionLight::class);

        return $harness;
    }
}
