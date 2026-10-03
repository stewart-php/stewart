<?php

declare(strict_types=1);

namespace Stewart\Contracts\Tests\Unit\Collection;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\App\AppId;
use Stewart\Contracts\App\Collection\AppIdCollection;
use Stewart\Contracts\Collection\TypedCollection;
use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\Selector\Collection\SelectorCollection;
use Stewart\Contracts\Selector\Selector;
use Stewart\Contracts\State\Collection\EntityStateCollection;
use Stewart\Contracts\State\EntityState;
use Stewart\Contracts\Tests\Fixtures\Collection\NumberedLabel;
use Stewart\Contracts\Tests\Fixtures\Collection\NumberedLabelCollection;
use Stewart\Contracts\Time\Instant;
use Stewart\Contracts\Topic\Collection\TopicEventCollection;
use Stewart\Contracts\Topic\TopicEvent;

#[CoversClass(TypedCollection::class)]
#[CoversClass(SelectorCollection::class)]
#[CoversClass(AppIdCollection::class)]
#[CoversClass(EntityStateCollection::class)]
#[CoversClass(TopicEventCollection::class)]
final class TypedCollectionTest extends TestCase
{
    public function testFilterKeepsConcreteTypeAndReindexesList(): void
    {
        $selectors = SelectorCollection::fromSpecs('light.hall', 'light.*', 'switch.fan');

        $globs = $selectors->filter(static fn(Selector $selector): bool => $selector->getPattern() === 'light.*');

        self::assertInstanceOf(SelectorCollection::class, $globs);
        self::assertSame([0], array_keys(iterator_to_array($globs)));
        self::assertCount(3, $selectors);
    }

    public function testFilterKeepsKeysOfKeyedCollection(): void
    {
        $states = EntityStateCollection::keyedByEntityId([
            new EntityState(new EntityId('light.hall'), 'on'),
            new EntityState(new EntityId('light.porch'), 'off'),
        ]);

        $on = $states->filter(static fn(EntityState $state): bool => $state->state === 'on');

        self::assertSame(['light.hall'], array_keys(iterator_to_array($on)));
    }

    public function testMapsToListInInsertionOrder(): void
    {
        $selectors = SelectorCollection::fromSpecs('a.b', 'c.*');

        self::assertSame(['exact:a.b', 'glob:c.*'], $selectors->mapToList(static fn(Selector $selector): string => $selector->toCanonicalKey()));
        self::assertSame('a.b', $selectors->getFirst()?->getPattern());
        self::assertCount(2, $selectors->listValues());
    }

    public function testFindsAndTestsByPredicate(): void
    {
        $selectors = SelectorCollection::fromSpecs('a.b', 'c.*');

        self::assertSame('c.*', $selectors->findFirstWhere(static fn(Selector $selector): bool => $selector->getPattern() === 'c.*')?->getPattern());
        self::assertNull($selectors->findFirstWhere(static fn(Selector $selector): bool => false));
        self::assertTrue($selectors->containsWhere(static fn(Selector $selector): bool => $selector->getPattern() === 'a.b'));
        self::assertTrue($selectors->anyMatches('c.x'));
        self::assertFalse($selectors->anyMatches('a.c'));
    }

    public function testSortedByReturnsNewCollection(): void
    {
        $selectors = SelectorCollection::fromSpecs('c.d', 'a.b');

        $sorted = $selectors->sortedBy(static fn(Selector $a, Selector $b): int => strcmp($a->getPattern(), $b->getPattern()));

        self::assertSame(['a.b', 'c.d'], $sorted->mapToList(static fn(Selector $selector): string => $selector->getPattern()));
        self::assertSame('c.d', $selectors->getFirst()?->getPattern());
    }

    public function testEmptyCollectionHoldsNothing(): void
    {
        self::assertTrue(SelectorCollection::empty()->isEmpty());
        self::assertNull(SelectorCollection::empty()->getFirst());
    }

    public function testAppIdCollectionLooksUpById(): void
    {
        $appIds = AppIdCollection::fromIds([new AppId('boiler'), new AppId('hall-light')]);

        self::assertTrue($appIds->containsId(new AppId('boiler')));
        self::assertFalse($appIds->containsId(new AppId('hall_light')));
        self::assertSame(['boiler', 'hall-light'], $appIds->toStrings());
    }

    public function testCopyHelpersLeaveOriginalUntouched(): void
    {
        $states = EntityStateCollection::keyedByEntityId([new EntityState(new EntityId('light.hall'), 'off')]);

        $replaced = $states->withState(new EntityState(new EntityId('light.hall'), 'on'));
        $removed = $replaced->withoutState(new EntityId('light.hall'));
        $door = new TopicEvent('door', null, new AppId('hall'), Instant::fromEpochMicroseconds(0));
        $events = TopicEventCollection::empty()->withTopicEvent($door);

        self::assertSame('off', $states->find(new EntityId('light.hall'))?->state);
        self::assertSame('on', $replaced->find(new EntityId('light.hall'))?->state);
        self::assertTrue($removed->isEmpty());
        self::assertSame([0, 1], array_keys(iterator_to_array($events->withTopicEvent($door))));
        self::assertCount(1, $events);
    }

    public function testIntegerKeysSurviveRemovalAndFilter(): void
    {
        $labels = NumberedLabelCollection::keyedByNumber([new NumberedLabel(0, 'zero'), new NumberedLabel(1, 'one'), new NumberedLabel(2, 'two')]);

        $withoutZero = $labels->withoutNumber(0);

        self::assertSame('one', $withoutZero->find(1)?->label);
        self::assertSame([1, 2], array_keys(iterator_to_array($withoutZero->filter(static fn(NumberedLabel $label): bool => true))));
    }
}
