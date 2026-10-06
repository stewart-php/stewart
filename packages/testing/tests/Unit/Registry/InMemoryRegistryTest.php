<?php

declare(strict_types=1);

namespace Stewart\Testing\Tests\Unit\Registry;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
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

#[CoversClass(InMemoryRegistry::class)]
final class InMemoryRegistryTest extends TestCase
{
    public function testSeededEntriesResolvePlacement(): void
    {
        $registry = new InMemoryRegistry()
            ->seedFloor(new Floor(new FloorId('ground'), 'Ground'))
            ->seedArea(new Area(new AreaId('kitchen'), 'Kitchen', new FloorId('ground')))
            ->seedLabel(new Label(new LabelId('night'), 'Night'))
            ->seedDevice(new Device(new DeviceId('bulb'), 'Bulb', areaId: new AreaId('kitchen'), labelIds: [new LabelId('night')]))
            ->seedEntity(new RegisteredEntity(new EntityId('light.ceiling'), new DeviceId('bulb')));

        $placement = $registry->findEntityPlacement('light.ceiling');

        self::assertSame('ground', $placement->floorId?->value);
        self::assertSame(['night'], $placement->labelIds->toStrings());
        self::assertSame('Bulb', $registry->findDevice('bulb')?->name);
        self::assertCount(1, $registry->listDevicesInArea('kitchen'));
        self::assertCount(1, $registry->listAreasOnFloor('ground'));
    }

    public function testSeedingAgainReplacesEntry(): void
    {
        $registry = new InMemoryRegistry()->seedArea(new Area(new AreaId('kitchen'), 'Kitchen'));
        self::assertSame('Kitchen', $registry->findAreaByName('kitchen')?->name);

        $registry->seedArea(new Area(new AreaId('kitchen'), 'Cookhouse'));

        self::assertSame('Cookhouse', $registry->findArea('kitchen')?->name);
        self::assertCount(1, $registry->listAreas());
    }
}
