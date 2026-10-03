<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Broker;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Selector\Selector;
use Stewart\Runtime\Broker\BrokerSubscription;
use Stewart\Runtime\Broker\SubscriptionRegistry;
use Stewart\Runtime\Broker\SubscriptionRoutes;
use Stewart\Runtime\Dispatch\SelectorIndex;
use Stewart\Runtime\Model\ResourceScope;
use Stewart\Runtime\Model\SubscriptionId;
use Stewart\Runtime\Model\SubscriptionKind;
use Stewart\Runtime\Model\WorkerId;

#[CoversClass(SubscriptionRegistry::class)]
#[CoversClass(SelectorIndex::class)]
#[CoversClass(SubscriptionRoutes::class)]
final class SubscriptionRegistryTest extends TestCase
{
    public function testExactAndPatternSubscriptionsAreGroupedByWorker(): void
    {
        $registry = new SubscriptionRegistry();
        $registry->add(self::createSubscription('w0:0', 0, 'light.hall'));
        $registry->add(self::createSubscription('w0:1', 0, 'light.*'));
        $registry->add(self::createSubscription('w1:0', 1, Selector::regex('#^light\.h#')));
        $registry->add(self::createSubscription('w1:1', 1, 'sensor.*'));

        $routes = $registry->findRoutes(SubscriptionKind::Event, 'light.hall');

        self::assertSame([0, 1], $routes->listWorkerIds()->toInts());
        self::assertSame(['w0:0', 'w0:1'], $routes->listSubscriptionIdsForWorker(new WorkerId(0))->toStrings());
        self::assertSame(['w1:0'], $routes->listSubscriptionIdsForWorker(new WorkerId(1))->toStrings());
        self::assertSame([], $routes->listSubscriptionIdsForWorker(new WorkerId(7))->toStrings());
    }

    public function testListsRegistrationsAndRemovesWorkersOwn(): void
    {
        $registry = new SubscriptionRegistry();
        $registry->add(self::createSubscription('w0:0', 0, 'light.hall'));
        $registry->add(self::createSubscription('w1:0', 1, 'sensor.*'));

        self::assertSame(['w0:0', 'w1:0'], $registry->listSubscriptions()->mapToList(static fn(BrokerSubscription $r): string => $r->subscriptionId->value));

        $registry->removeWorkerSubscriptions(new WorkerId(0));

        self::assertSame(['w1:0'], $registry->listSubscriptions()->mapToList(static fn(BrokerSubscription $r): string => $r->subscriptionId->value));
    }

    public function testKindsDoNotMatchEachOther(): void
    {
        $registry = new SubscriptionRegistry();
        $registry->add(self::createSubscription('w0:0', 0, 'demo.*', SubscriptionKind::Topic));

        self::assertSame([], $registry->findRoutes(SubscriptionKind::Event, 'demo.triggered')->listWorkerIds()->toInts());
        self::assertSame([0], $registry->findRoutes(SubscriptionKind::Topic, 'demo.triggered')->listWorkerIds()->toInts());
    }

    public function testSameNameUnderADifferentKindRoutesSeparately(): void
    {
        $registry = new SubscriptionRegistry();
        $registry->add(self::createSubscription('w0:0', 0, 'zha_event', SubscriptionKind::Event));
        $registry->add(self::createSubscription('w1:0', 1, 'zha_event', SubscriptionKind::Topic));

        self::assertSame(['w0:0'], $registry->findRoutes(SubscriptionKind::Event, 'zha_event')->listSubscriptionIdsForWorker(new WorkerId(0))->toStrings());
        self::assertSame([1], $registry->findRoutes(SubscriptionKind::Topic, 'zha_event')->listWorkerIds()->toInts());
        self::assertSame([], $registry->findRoutes(SubscriptionKind::StateChange, 'zha_event')->listWorkerIds()->toInts());
    }

    public function testWorkerCanOnlyRemoveItsOwnSubscriptions(): void
    {
        $registry = new SubscriptionRegistry();
        $registry->add(self::createSubscription('w0:0', 0, 'light.hall'));

        $registry->remove(new SubscriptionId('w0:0'), ownedBy: new WorkerId(1));
        self::assertSame([0], $registry->findRoutes(SubscriptionKind::Event, 'light.hall')->listWorkerIds()->toInts());

        $registry->remove(new SubscriptionId('w0:0'), ownedBy: new WorkerId(0));
        self::assertSame([], $registry->findRoutes(SubscriptionKind::Event, 'light.hall')->listWorkerIds()->toInts());
    }

    public function testRemovingAWorkerDropsEverythingItRegistered(): void
    {
        $registry = new SubscriptionRegistry();
        $registry->add(self::createSubscription('w0:0', 0, 'light.hall'));
        $registry->add(self::createSubscription('w0:1', 0, 'light.*'));
        $registry->add(self::createSubscription('w1:0', 1, 'light.*'));

        $registry->removeWorkerSubscriptions(new WorkerId(0));

        self::assertSame([1], $registry->findRoutes(SubscriptionKind::Event, 'light.hall')->listWorkerIds()->toInts());
        self::assertSame(1, $registry->getRoutingStats()->subscriptions);
    }

    public function testLookupsAreCachedUntilRegistrationsChange(): void
    {
        $registry = new SubscriptionRegistry();
        $registry->add(self::createSubscription('w0:0', 0, 'light.*'));

        $registry->findRoutes(SubscriptionKind::Event, 'light.hall');
        $registry->findRoutes(SubscriptionKind::Event, 'light.hall');
        $registry->add(self::createSubscription('w1:0', 1, 'light.hall'));
        $routes = $registry->findRoutes(SubscriptionKind::Event, 'light.hall');

        self::assertSame([0, 1], $routes->listWorkerIds()->toInts());
        self::assertSame(1, $registry->getRoutingStats()->cacheHits);
        self::assertSame(2, $registry->getRoutingStats()->cacheMisses);
    }

    private static function createSubscription(string $id, int $workerId, string|Selector $selector, SubscriptionKind $kind = SubscriptionKind::Event): BrokerSubscription
    {
        return new BrokerSubscription(new SubscriptionId($id), new WorkerId($workerId), ResourceScope::forApp(new AppId('app')), $kind, Selector::fromSpec($selector));
    }
}
