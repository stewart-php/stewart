<?php

declare(strict_types=1);

namespace Stewart\Contracts\Tests\Unit\Registry;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\Registry\EntityPlacement;
use Stewart\Contracts\Registry\IndexedRegistry;
use Stewart\Contracts\Tests\Fixtures\Registry\RegistryFixture;

#[CoversClass(IndexedRegistry::class)]
#[CoversClass(EntityPlacement::class)]
final class IndexedRegistryTest extends TestCase
{
    public function testEntityInheritsDeviceArea(): void
    {
        $placement = RegistryFixture::createRegistry()->findEntityPlacement('light.kitchen_ceiling');

        self::assertSame('kitchen', $placement->areaId?->value);
        self::assertSame('ground', $placement->floorId?->value);
        self::assertSame('ceiling_bulb', $placement->deviceId?->value);
    }

    public function testEntityAreaOverridesDeviceArea(): void
    {
        $placement = RegistryFixture::createRegistry()->findEntityPlacement('light.hall_spot');

        self::assertSame('hall', $placement->areaId?->value);
        self::assertSame('ground', $placement->floorId?->value);
    }

    public function testLabelsUniteEntityDeviceAndArea(): void
    {
        $placement = RegistryFixture::createRegistry()->findEntityPlacement('light.kitchen_ceiling');

        self::assertSame(['night', 'hue', 'cooking'], $placement->labelIds->toStrings());
    }

    public function testCategorizedOrHiddenEntityIsNotTargetable(): void
    {
        $registry = RegistryFixture::createRegistry();

        self::assertFalse($registry->findEntityPlacement('sensor.ceiling_bulb_signal')->indirectlyTargetable);
        self::assertFalse($registry->findEntityPlacement('switch.hidden_relay')->indirectlyTargetable);
        self::assertTrue($registry->findEntityPlacement('light.kitchen_ceiling')->indirectlyTargetable);
    }

    public function testUnregisteredEntityHasEmptyPlacement(): void
    {
        $placement = IndexedRegistry::empty()->findEntityPlacement(new EntityId('light.yaml_only'));

        self::assertNull($placement->areaId);
        self::assertNull($placement->deviceId);
        self::assertTrue($placement->labelIds->isEmpty());
    }

    public function testLooksUpByIdNameAndParent(): void
    {
        $registry = RegistryFixture::createRegistry();

        self::assertSame('Kitchen', $registry->findArea('kitchen')?->name);
        self::assertSame('hall', $registry->findAreaByName('entrance')?->areaId->value);
        self::assertSame(['kitchen', 'hall'], array_keys(iterator_to_array($registry->listAreasOnFloor('ground'))));
        self::assertSame('ground', $registry->findFloorByName('Ground floor')?->floorId->value);
        self::assertSame('night', $registry->findLabelByName('Night')?->labelId->value);
        self::assertSame('Hue', $registry->findLabel('hue')?->name);
        self::assertSame('Upstairs', $registry->findFloor('upstairs')?->name);
        self::assertSame(['ceiling_bulb'], array_keys(iterator_to_array($registry->listDevicesInArea('kitchen'))));
        self::assertSame('Ceiling', $registry->findDevice('ceiling_bulb')?->getDisplayName());
        self::assertSame('hall', $registry->findEntity('light.hall_spot')?->areaId?->value);
        self::assertCount(4, $registry->listEntities());
        self::assertCount(2, $registry->listFloors());
        self::assertCount(3, $registry->listLabels());
        self::assertCount(2, $registry->listDevices());
        self::assertCount(3, $registry->listAreas());
    }
}
