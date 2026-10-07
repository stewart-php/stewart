<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\State;

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
use Stewart\Contracts\Registry\EntityFilter;
use Stewart\Contracts\Registry\IndexedRegistry;
use Stewart\Contracts\Registry\RegisteredEntity;
use Stewart\Contracts\Selector\Selector;
use Stewart\Contracts\State\EntityState;
use Stewart\Runtime\Registry\RegistryCache;
use Stewart\Runtime\State\CachedStateReader;
use Stewart\Runtime\State\StateCache;

#[CoversClass(CachedStateReader::class)]
final class CachedStateReaderTest extends TestCase
{
    private StateCache $states;

    private RegistryCache $registry;

    protected function setUp(): void
    {
        $this->states = new StateCache();
        $this->states->replaceAll([new EntityState(new EntityId('light.kitchen'), 'on'), new EntityState(new EntityId('light.porch'), 'off')]);
        $this->registry = new RegistryCache();
        $this->registry->replaceIfNewer(IndexedRegistry::fromParts(
            AreaCollection::keyedByAreaId([new Area(new AreaId('kitchen'), 'Kitchen')]),
            FloorCollection::empty(),
            LabelCollection::empty(),
            DeviceCollection::empty(),
            RegisteredEntityCollection::keyedByEntityId([new RegisteredEntity(new EntityId('light.kitchen'), areaId: new AreaId('kitchen'))]),
        ), 1);
    }

    public function testReadsStatesMatchingSelector(): void
    {
        $reader = new CachedStateReader($this->states, $this->registry, Selector::exact('light.porch'));

        self::assertSame(['light.porch'], $reader->readCurrentStates()->listEntityIds()->toStrings());
    }

    public function testReadsStatesMatchingEntityFilter(): void
    {
        $reader = new CachedStateReader($this->states, $this->registry, EntityFilter::inArea('kitchen'));

        self::assertSame(['light.kitchen'], $reader->readCurrentStates()->listEntityIds()->toStrings());
    }

    public function testReadsCacheAtCallTime(): void
    {
        $reader = new CachedStateReader($this->states, $this->registry, Selector::fromSpec('switch.*'));
        $this->states->replaceAll([new EntityState(new EntityId('switch.fan'), 'on')]);

        self::assertSame(['switch.fan'], $reader->readCurrentStates()->listEntityIds()->toStrings());
    }
}
