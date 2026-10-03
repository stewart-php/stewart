<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\State;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\Selector\Selector;
use Stewart\Contracts\State\EntityState;
use Stewart\Contracts\State\StateChange;
use Stewart\Runtime\State\StateCache;

#[CoversClass(StateCache::class)]
final class StateCacheTest extends TestCase
{
    public function testReplaceAllSeedsStates(): void
    {
        $cache = new StateCache();
        $cache->replaceAll([
            new EntityState(new EntityId('light.hall'), 'on', ['brightness' => 254]),
            new EntityState(new EntityId('sensor.temp'), '21.5'),
        ]);

        self::assertSame(2, $cache->count());
        self::assertSame('on', $cache->find(new EntityId('light.hall'))?->state);
        self::assertSame(254, $cache->find(new EntityId('light.hall'))->getAttribute('brightness'));
        self::assertNull($cache->find(new EntityId('nope.missing')));
    }

    public function testRemovalLeavesCache(): void
    {
        $cache = new StateCache();
        $cache->replaceAll([new EntityState(new EntityId('light.hall'), 'on')]);

        $cache->applyChange(new StateChange(new EntityId('light.hall'), new EntityState(new EntityId('light.hall'), 'on'), null));

        self::assertNull($cache->find(new EntityId('light.hall')));
        self::assertSame(0, $cache->count());
    }

    public function testMatchingUsesSelectors(): void
    {
        $cache = new StateCache();
        $cache->replaceAll([
            new EntityState(new EntityId('sensor.phone_battery'), '80'),
            new EntityState(new EntityId('sensor.tablet_battery'), '40'),
            new EntityState(new EntityId('light.hall'), 'on'),
        ]);

        self::assertCount(2, $cache->filterBySelector(Selector::fromSpec('sensor.*_battery')));
        self::assertCount(1, $cache->filterBySelector(Selector::fromSpec('light.hall')));
        self::assertCount(3, $cache->filterBySelector(null));
        self::assertTrue($cache->filterBySelector(Selector::fromSpec('switch.nothing'))->isEmpty());
    }

    public function testReplaceAllDropsPreviousStates(): void
    {
        $cache = new StateCache();
        $cache->replaceAll([new EntityState(new EntityId('light.old'), 'on')]);

        $cache->replaceAll([new EntityState(new EntityId('light.new'), 'off')]);

        self::assertNull($cache->find(new EntityId('light.old')));
        self::assertNotNull($cache->find(new EntityId('light.new')));
    }

    public function testListsEntityIds(): void
    {
        $cache = new StateCache();
        $cache->replaceAll([new EntityState(new EntityId('light.hall'), 'on'), new EntityState(new EntityId('sensor.temp'), '20')]);

        self::assertSame(['light.hall', 'sensor.temp'], $cache->listEntityIds()->toStrings());
    }
}
