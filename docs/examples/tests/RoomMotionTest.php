<?php

declare(strict_types=1);

namespace App\Tests;

use App\RoomMotion;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\Registry\Area;
use Stewart\Contracts\Registry\AreaId;
use Stewart\Contracts\Registry\LabelId;
use Stewart\Contracts\Registry\RegisteredEntity;
use Stewart\Testing\App\AppHarness;

final class RoomMotionTest extends TestCase
{
    public function testMotionInTheAreaTurnsItsLightsOn(): void
    {
        $harness = new AppHarness();
        $harness->ha->registry
            ->seedArea(new Area(new AreaId('kitchen'), 'Kitchen'))
            ->seedEntity(new RegisteredEntity(new EntityId('binary_sensor.kitchen_pir'), areaId: new AreaId('kitchen'), labelIds: [new LabelId('motion')]))
            ->seedEntity(new RegisteredEntity(new EntityId('binary_sensor.hall_pir'), areaId: new AreaId('hall'), labelIds: [new LabelId('motion')]));
        $harness->ha->seedState('binary_sensor.kitchen_pir', 'off');
        $harness->ha->seedState('binary_sensor.hall_pir', 'off');
        $harness->startApp(RoomMotion::class);

        $harness->ha->pushState('binary_sensor.hall_pir', 'on');
        self::assertTrue($harness->ha->calls->isEmpty());

        $harness->ha->pushState('binary_sensor.kitchen_pir', 'on');
        self::assertSame('light.turn_on', $harness->ha->getLastCall()->getActionName());
    }
}
