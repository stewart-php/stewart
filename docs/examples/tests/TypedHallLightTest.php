<?php

declare(strict_types=1);

namespace App\Tests;

use App\TypedHallLight;
use PHPUnit\Framework\TestCase;
use Stewart\Testing\App\AppHarness;

final class TypedHallLightTest extends TestCase
{
    public function testMotionTurnsTheHallLightOnAndNotifies(): void
    {
        $harness = new AppHarness();
        $harness->ha->seedState('binary_sensor.hall_motion', 'off');
        $harness->ha->seedState('light.hall', 'off');
        $harness->startApp(TypedHallLight::class);

        $harness->ha->pushState('binary_sensor.hall_motion', 'on');

        $calls = $harness->ha->calls->mapToList(static fn($call): string => $call->getActionName());
        self::assertSame(['light.turn_on', 'notify.mobile_app_phone'], $calls);
    }
}
