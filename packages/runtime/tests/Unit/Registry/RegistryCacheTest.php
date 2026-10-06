<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Registry;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\Registry\Area;
use Stewart\Contracts\Registry\AreaId;
use Stewart\Contracts\Registry\Collection\AreaCollection;
use Stewart\Contracts\Registry\Collection\DeviceCollection;
use Stewart\Contracts\Registry\Collection\FloorCollection;
use Stewart\Contracts\Registry\Collection\LabelCollection;
use Stewart\Contracts\Registry\Collection\RegisteredEntityCollection;
use Stewart\Contracts\Registry\IndexedRegistry;
use Stewart\Contracts\Registry\RegisteredEntity;
use Stewart\Runtime\Registry\RegistryCache;

#[CoversClass(RegistryCache::class)]
final class RegistryCacheTest extends TestCase
{
    public function testStartsEmptyAtRevisionZero(): void
    {
        $cache = new RegistryCache();

        self::assertSame(0, $cache->getRevision());
        self::assertTrue($cache->listAreas()->isEmpty());
    }

    public function testNewerRevisionReplacesRegistry(): void
    {
        $cache = new RegistryCache();

        self::assertTrue($cache->replaceIfNewer(self::createRegistryWithKitchen('Kitchen'), 2));
        self::assertSame(2, $cache->getRevision());
        self::assertSame('Kitchen', $cache->findArea('kitchen')?->name);
        self::assertSame('kitchen', $cache->findEntityPlacement('light.ceiling')->areaId?->value);
    }

    public function testOlderRevisionIsIgnored(): void
    {
        $cache = new RegistryCache();
        $cache->replaceIfNewer(self::createRegistryWithKitchen('Kitchen'), 2);

        self::assertFalse($cache->replaceIfNewer(self::createRegistryWithKitchen('Old kitchen'), 2));
        self::assertSame('Kitchen', $cache->findAreaByName('kitchen')?->name);
    }

    private static function createRegistryWithKitchen(string $name): IndexedRegistry
    {
        return IndexedRegistry::fromParts(
            AreaCollection::keyedByAreaId([new Area(new AreaId('kitchen'), $name)]),
            FloorCollection::empty(),
            LabelCollection::empty(),
            DeviceCollection::empty(),
            RegisteredEntityCollection::keyedByEntityId([new RegisteredEntity(new EntityId('light.ceiling'), areaId: new AreaId('kitchen'))]),
        );
    }
}
