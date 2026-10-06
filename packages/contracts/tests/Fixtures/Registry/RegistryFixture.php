<?php

declare(strict_types=1);

namespace Stewart\Contracts\Tests\Fixtures\Registry;

use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\Registry\Area;
use Stewart\Contracts\Registry\AreaId;
use Stewart\Contracts\Registry\Collection\AreaCollection;
use Stewart\Contracts\Registry\Collection\DeviceCollection;
use Stewart\Contracts\Registry\Collection\FloorCollection;
use Stewart\Contracts\Registry\Collection\LabelCollection;
use Stewart\Contracts\Registry\Collection\RegisteredEntityCollection;
use Stewart\Contracts\Registry\Device;
use Stewart\Contracts\Registry\DeviceId;
use Stewart\Contracts\Registry\Floor;
use Stewart\Contracts\Registry\FloorId;
use Stewart\Contracts\Registry\IndexedRegistry;
use Stewart\Contracts\Registry\Label;
use Stewart\Contracts\Registry\LabelId;
use Stewart\Contracts\Registry\RegisteredEntity;

final class RegistryFixture
{
    public static function createRegistry(): IndexedRegistry
    {
        $ground = new FloorId('ground');
        $kitchen = new AreaId('kitchen');
        $hall = new AreaId('hall');
        $ceilingBulb = new DeviceId('ceiling_bulb');
        $relay = new DeviceId('relay');

        return IndexedRegistry::fromParts(
            AreaCollection::keyedByAreaId([
                new Area($kitchen, 'Kitchen', $ground, labelIds: [new LabelId('cooking')]),
                new Area($hall, 'Hall', $ground, ['Entrance']),
                new Area(new AreaId('bedroom'), 'Bedroom', new FloorId('upstairs')),
            ]),
            FloorCollection::keyedByFloorId([new Floor($ground, 'Ground floor', 0), new Floor(new FloorId('upstairs'), 'Upstairs', 1)]),
            LabelCollection::keyedByLabelId([new Label(new LabelId('night'), 'Night'), new Label(new LabelId('hue'), 'Hue'), new Label(new LabelId('cooking'), 'Cooking')]),
            DeviceCollection::keyedByDeviceId([
                new Device($ceilingBulb, 'Hue bulb', 'Ceiling', $kitchen, [new LabelId('hue')]),
                new Device($relay, 'Relay', areaId: $hall),
            ]),
            RegisteredEntityCollection::keyedByEntityId([
                new RegisteredEntity(new EntityId('light.kitchen_ceiling'), $ceilingBulb, labelIds: [new LabelId('night'), new LabelId('hue')]),
                new RegisteredEntity(new EntityId('sensor.ceiling_bulb_signal'), $ceilingBulb, entityCategory: 'diagnostic'),
                new RegisteredEntity(new EntityId('light.hall_spot'), $ceilingBulb, $hall),
                new RegisteredEntity(new EntityId('switch.hidden_relay'), $relay, hiddenBy: 'user'),
            ]),
        );
    }
}
