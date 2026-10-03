<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Dispatch;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Selector\Selector;
use Stewart\Runtime\Dispatch\IndexedSelector;
use Stewart\Runtime\Dispatch\SelectorIndex;
use Stewart\Runtime\Model\RoutingStats;
use Stewart\Runtime\Model\SubscriptionId;
use Stewart\Runtime\Model\SubscriptionKind;

#[CoversClass(SelectorIndex::class)]
#[CoversClass(IndexedSelector::class)]
#[CoversClass(RoutingStats::class)]
final class SelectorIndexTest extends TestCase
{
    public function testExactGlobAndRegexSelectorsAllMatch(): void
    {
        $index = new SelectorIndex();
        $index->add(new SubscriptionId('exact'), SubscriptionKind::StateChange, Selector::fromSpec('light.hall'));
        $index->add(new SubscriptionId('glob'), SubscriptionKind::StateChange, Selector::fromSpec('light.*'));
        $index->add(new SubscriptionId('regex'), SubscriptionKind::StateChange, Selector::regex('#^light\.h#'));
        $index->add(new SubscriptionId('other'), SubscriptionKind::StateChange, Selector::fromSpec('sensor.*'));

        self::assertSame(['exact', 'glob', 'regex'], $index->findMatching(SubscriptionKind::StateChange, 'light.hall')->toStrings());
        self::assertSame(['glob'], $index->findMatching(SubscriptionKind::StateChange, 'light.porch')->toStrings());
    }

    public function testKindsAreKeptApart(): void
    {
        $index = new SelectorIndex();
        $index->add(new SubscriptionId('state'), SubscriptionKind::StateChange, Selector::fromSpec('zha_event'));
        $index->add(new SubscriptionId('topic'), SubscriptionKind::Topic, Selector::fromSpec('zha_*'));

        self::assertSame(['state'], $index->findMatching(SubscriptionKind::StateChange, 'zha_event')->toStrings());
        self::assertSame(['topic'], $index->findMatching(SubscriptionKind::Topic, 'zha_event')->toStrings());
        self::assertSame([], $index->findMatching(SubscriptionKind::Event, 'zha_event')->toStrings());
    }

    public function testRemovedAndReplacedSelectorsStopMatching(): void
    {
        $index = new SelectorIndex();
        $index->add(new SubscriptionId('a'), SubscriptionKind::StateChange, Selector::fromSpec('light.hall'));
        $index->add(new SubscriptionId('b'), SubscriptionKind::StateChange, Selector::fromSpec('light.*'));
        $index->findMatching(SubscriptionKind::StateChange, 'light.hall');

        $index->remove(new SubscriptionId('b'));
        $index->add(new SubscriptionId('a'), SubscriptionKind::StateChange, Selector::fromSpec('light.porch'));
        $index->remove(new SubscriptionId('unknown'));

        self::assertSame([], $index->findMatching(SubscriptionKind::StateChange, 'light.hall')->toStrings());
        self::assertSame(['a'], $index->findMatching(SubscriptionKind::StateChange, 'light.porch')->toStrings());
        self::assertSame(1, $index->getRoutingStats()->subscriptions);
        self::assertSame(0, $index->getRoutingStats()->patterns);
    }

    public function testRepeatedLookupsHitTheCacheUntilTheIndexChanges(): void
    {
        $index = new SelectorIndex();
        $index->add(new SubscriptionId('a'), SubscriptionKind::StateChange, Selector::fromSpec('light.*'));

        $index->findMatching(SubscriptionKind::StateChange, 'light.hall');
        $index->findMatching(SubscriptionKind::StateChange, 'light.hall');
        $index->add(new SubscriptionId('b'), SubscriptionKind::StateChange, Selector::fromSpec('light.hall'));

        self::assertSame(['b', 'a'], $index->findMatching(SubscriptionKind::StateChange, 'light.hall')->toStrings());

        $stats = $index->getRoutingStats();
        self::assertSame(1, $stats->cacheHits);
        self::assertSame(2, $stats->cacheMisses);
        self::assertSame(1, $stats->cachedKeys);
        self::assertSame(1, $stats->patterns);
    }
}
