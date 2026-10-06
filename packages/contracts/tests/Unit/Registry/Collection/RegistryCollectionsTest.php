<?php

declare(strict_types=1);

namespace Stewart\Contracts\Tests\Unit\Registry\Collection;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\Registry\Area;
use Stewart\Contracts\Registry\AreaId;
use Stewart\Contracts\Registry\Collection\AreaCollection;
use Stewart\Contracts\Registry\Collection\AreaIdCollection;
use Stewart\Contracts\Registry\Collection\DeviceCollection;
use Stewart\Contracts\Registry\Collection\DeviceIdCollection;
use Stewart\Contracts\Registry\Collection\FloorCollection;
use Stewart\Contracts\Registry\Collection\FloorIdCollection;
use Stewart\Contracts\Registry\Collection\LabelCollection;
use Stewart\Contracts\Registry\Collection\LabelIdCollection;
use Stewart\Contracts\Registry\Collection\RegisteredEntityCollection;
use Stewart\Contracts\Registry\Collection\RegistryIdCollection;
use Stewart\Contracts\Registry\Device;
use Stewart\Contracts\Registry\DeviceId;
use Stewart\Contracts\Registry\Floor;
use Stewart\Contracts\Registry\FloorId;
use Stewart\Contracts\Registry\Label;
use Stewart\Contracts\Registry\LabelId;
use Stewart\Contracts\Registry\RegisteredEntity;

#[CoversClass(AreaCollection::class)]
#[CoversClass(FloorCollection::class)]
#[CoversClass(LabelCollection::class)]
#[CoversClass(DeviceCollection::class)]
#[CoversClass(RegisteredEntityCollection::class)]
#[CoversClass(RegistryIdCollection::class)]
#[CoversClass(AreaIdCollection::class)]
#[CoversClass(FloorIdCollection::class)]
#[CoversClass(LabelIdCollection::class)]
#[CoversClass(DeviceIdCollection::class)]
final class RegistryCollectionsTest extends TestCase
{
    public function testKeyedCollectionsFindById(): void
    {
        $areas = AreaCollection::keyedByAreaId([new Area(new AreaId('kitchen'), 'Kitchen'), new Area(new AreaId('hall'), 'Hall')]);

        self::assertSame('Hall', $areas->find(new AreaId('hall'))?->name);
        self::assertNull($areas->find(new AreaId('attic')));
        self::assertSame('Upstairs', FloorCollection::keyedByFloorId([new Floor(new FloorId('up'), 'Upstairs')])->find(new FloorId('up'))?->name);
        self::assertSame('Night', LabelCollection::keyedByLabelId([new Label(new LabelId('night'), 'Night')])->find(new LabelId('night'))?->name);
        self::assertSame('Bulb', DeviceCollection::keyedByDeviceId([new Device(new DeviceId('d1'), 'Bulb')])->find(new DeviceId('d1'))?->name);

        $entities = RegisteredEntityCollection::keyedByEntityId([new RegisteredEntity(new EntityId('light.hall'), name: 'Hall')]);

        self::assertSame('Hall', $entities->find(new EntityId('light.hall'))?->name);
    }

    public function testIdCollectionContainsAnyOf(): void
    {
        $ids = AreaIdCollection::fromIds([new AreaId('kitchen'), new AreaId('hall')]);

        self::assertTrue($ids->contains(new AreaId('hall')));
        self::assertFalse($ids->contains(new AreaId('attic')));
        self::assertTrue($ids->containsAnyOf(AreaIdCollection::fromIds([new AreaId('attic'), new AreaId('kitchen')])));
        self::assertFalse($ids->containsAnyOf(AreaIdCollection::empty()));
        self::assertSame(['up'], FloorIdCollection::fromIds([new FloorId('up')])->toStrings());
        self::assertSame(['d1'], DeviceIdCollection::fromIds([new DeviceId('d1')])->toStrings());
        self::assertSame(['night'], LabelIdCollection::fromIds([new LabelId('night')])->toStrings());
    }
}
