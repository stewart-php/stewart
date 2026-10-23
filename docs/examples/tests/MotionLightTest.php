<?php

declare(strict_types=1);

namespace Automations\Tests;

use Automations\MotionLight;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Time\Duration;
use Stewart\Testing\App\AppHarness;

final class MotionLightTest extends TestCase
{
    public function testEachRoomSwitchesItsOwnLight(): void
    {
        $hall = self::startRoom('hall-motion-light', ['motionSensor' => 'binary_sensor.hall_motion', 'light' => 'light.hall']);
        $landing = self::startRoom('landing-motion-light', ['motionSensor' => 'binary_sensor.landing_motion', 'light' => 'light.landing']);

        $hall->ha->pushState('binary_sensor.hall_motion', 'on');
        $landing->ha->pushState('binary_sensor.landing_motion', 'on');

        self::assertSame(['light.hall'], $hall->ha->getLastCall()->listTargetedEntityIds());
        self::assertSame(['light.landing'], $landing->ha->getLastCall()->listTargetedEntityIds());
    }

    public function testTheLightGoesOffAfterTheRoomsOwnDelay(): void
    {
        $landing = self::startRoom('landing-motion-light', ['motionSensor' => 'binary_sensor.landing_motion', 'light' => 'light.landing', 'offAfterMinutes' => 1]);

        $landing->ha->pushState('binary_sensor.landing_motion', 'on');
        $landing->ha->pushState('binary_sensor.landing_motion', 'off');
        $landing->advanceTime(Duration::minutes(1));

        self::assertSame('light.turn_off', $landing->ha->getLastCall()->getActionName());
    }

    /** @param array{motionSensor: string, light: string, offAfterMinutes?: int} $options */
    private static function startRoom(string $appId, array $options): AppHarness
    {
        $harness = new AppHarness();
        $harness->ha->seedState($options['motionSensor'], 'off');
        $harness->startApp(MotionLight::class, $options, appId: $appId);

        return $harness;
    }
}
