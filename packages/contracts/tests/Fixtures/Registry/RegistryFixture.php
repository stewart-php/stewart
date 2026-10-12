<?php

declare(strict_types=1);

namespace Stewart\Contracts\Tests\Fixtures\Registry;

use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\Registry\Area;
use Stewart\Contracts\Registry\AreaId;
use Stewart\Contracts\Registry\Device;
use Stewart\Contracts\Registry\DeviceId;
use Stewart\Contracts\Registry\Floor;
use Stewart\Contracts\Registry\FloorId;
use Stewart\Contracts\Registry\Label;
use Stewart\Contracts\Registry\LabelId;
use Stewart\Contracts\Registry\RegisteredEntity;
use Stewart\Testing\Registry\InMemoryRegistry;

final class RegistryFixture
{
    public static function createRegistry(): InMemoryRegistry
    {
        $ground = new FloorId('ground');
        $kitchen = new AreaId('kitchen');
        $hall = new AreaId('hall');
        $ceilingBulb = new DeviceId('ceiling_bulb');
        $relay = new DeviceId('relay');

        return new InMemoryRegistry()
            ->seedArea(new Area($kitchen, 'Kitchen', $ground, labelIds: [new LabelId('cooking')]))
            ->seedArea(new Area($hall, 'Hall', $ground, ['Entrance']))
            ->seedArea(new Area(new AreaId('bedroom'), 'Bedroom', new FloorId('upstairs')))
            ->seedFloor(new Floor($ground, 'Ground floor', 0))
            ->seedFloor(new Floor(new FloorId('upstairs'), 'Upstairs', 1))
            ->seedLabel(new Label(new LabelId('night'), 'Night'))
            ->seedLabel(new Label(new LabelId('hue'), 'Hue'))
            ->seedLabel(new Label(new LabelId('cooking'), 'Cooking'))
            ->seedDevice(new Device($ceilingBulb, 'Hue bulb', 'Ceiling', $kitchen, [new LabelId('hue')]))
            ->seedDevice(new Device($relay, 'Relay', areaId: $hall))
            ->seedEntity(new RegisteredEntity(new EntityId('light.kitchen_ceiling'), $ceilingBulb, labelIds: [new LabelId('night'), new LabelId('hue')]))
            ->seedEntity(new RegisteredEntity(new EntityId('sensor.ceiling_bulb_signal'), $ceilingBulb, entityCategory: 'diagnostic'))
            ->seedEntity(new RegisteredEntity(new EntityId('light.hall_spot'), $ceilingBulb, $hall))
            ->seedEntity(new RegisteredEntity(new EntityId('switch.hidden_relay'), $relay, hiddenBy: 'user'));
    }
}
