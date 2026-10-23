<?php

declare(strict_types=1);

namespace App\Tests;

use App\PorchLight;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Time\Duration;
use Stewart\Testing\App\AppHarness;

final class PorchLightTest extends TestCase
{
    private AppHarness $harness;

    protected function setUp(): void
    {
        // The harness starts at midnight, and its sun sets at 18:00.
        $this->harness = new AppHarness();
        $this->harness->startApp(PorchLight::class, ['light' => 'light.front_porch']);
    }

    public function testTheLightTurnsOnAtSunset(): void
    {
        $this->harness->advanceTime(Duration::hours(18));

        $call = $this->harness->ha->getLastCall();
        self::assertSame('light.turn_on', $call->getActionName());
        self::assertSame(['light.front_porch'], $call->listTargetedEntityIds());
    }

    public function testTheLightTurnsOffAtBedtime(): void
    {
        $this->harness->advanceTime(Duration::hours(23));

        self::assertSame('light.turn_off', $this->harness->ha->getLastCall()->getActionName());
    }
}
