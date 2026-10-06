<?php

declare(strict_types=1);

namespace Stewart\Contracts\Tests\Unit\State\Collection;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\Registry\EntityFilter;
use Stewart\Contracts\Selector\Selector;
use Stewart\Contracts\State\Collection\EntityStateCollection;
use Stewart\Contracts\State\EntityState;
use Stewart\Contracts\Tests\Fixtures\Registry\RegistryFixture;
use Stewart\Contracts\Time\Instant;

#[CoversClass(EntityStateCollection::class)]
final class EntityStateCollectionTest extends TestCase
{
    public function testStatesAreKeyedByEntityId(): void
    {
        $states = EntityStateCollection::keyedByEntityId([self::createState('light.hall', 'on', 1), self::createState('switch.fan', 'off', 1)]);

        self::assertSame(['light.hall', 'switch.fan'], self::listKeys($states));
        self::assertSame('on', $states->find(new EntityId('light.hall'))?->state);
        self::assertSame('off', $states->find(new EntityId('switch.fan'))?->state);
        self::assertNull($states->find(new EntityId('light.porch')));
        self::assertEquals([new EntityId('light.hall'), new EntityId('switch.fan')], $states->listEntityIds()->listValues());
    }

    public function testMatchingFiltersBySelector(): void
    {
        $states = EntityStateCollection::keyedByEntityId([self::createState('light.hall', 'on', 1), self::createState('light.porch', 'on', 1), self::createState('switch.fan', 'off', 1)]);

        self::assertSame(['light.hall', 'light.porch'], self::listKeys($states->filterBySelector(Selector::glob('light.*'))));
        self::assertSame(['switch.fan'], self::listKeys($states->filterBySelector(Selector::exact('switch.fan'))));
        self::assertTrue($states->filterBySelector(Selector::exact('switch.none'))->isEmpty());
    }

    public function testEntityFilterNarrowsStates(): void
    {
        $states = EntityStateCollection::keyedByEntityId([self::createState('light.kitchen_ceiling', 'on', 1), self::createState('light.hall_spot', 'on', 1)]);

        self::assertSame(['light.kitchen_ceiling'], self::listKeys($states->filterByEntityFilter(EntityFilter::inArea('kitchen'), RegistryFixture::createRegistry())));
    }

    public function testStatesGroupByDomain(): void
    {
        $states = EntityStateCollection::keyedByEntityId([self::createState('switch.fan', 'off', 1), self::createState('light.hall', 'on', 1), self::createState('light.porch', 'on', 1)]);

        self::assertSame(['light', 'switch'], $states->listDomains());
        self::assertSame(['light.hall', 'light.porch'], self::listKeys($states->groupByDomain()['light']));
    }

    /** @return list<string> */
    private static function listKeys(EntityStateCollection $states): array
    {
        return array_keys(iterator_to_array($states));
    }

    private static function createState(string $entityId, string $state, int $updatedSecond): EntityState
    {
        return new EntityState(new EntityId($entityId), $state, [], self::createInstantAt($updatedSecond), self::createInstantAt($updatedSecond));
    }

    private static function createInstantAt(int $second): Instant
    {
        return Instant::fromEpochMicroseconds($second * 1_000_000);
    }
}
