<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Dispatch;

use Closure;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Connection\ConnectionEvent;
use Stewart\Contracts\Connection\ConnectionLost;
use Stewart\Contracts\Connection\ConnectionRestored;
use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\Event\HaEvent;
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
use Stewart\Contracts\State\StateChange;
use Stewart\Contracts\State\StateChangeOrigin;
use Stewart\Contracts\Stream\SubscriptionScope;
use Stewart\Contracts\Subscription;
use Stewart\Contracts\Time\Duration;
use Stewart\Contracts\Time\Instant;
use Stewart\Contracts\Topic\TopicEvent;
use Stewart\Contracts\Trigger\HaTrigger;
use Stewart\Contracts\Trigger\TriggerEvent;
use Stewart\Contracts\Trigger\TriggerSpec;
use Stewart\Runtime\Dispatch\EntityFilterIndex;
use Stewart\Runtime\Dispatch\LocalDispatcher;
use Stewart\Runtime\Dispatch\LocalSubscription;
use Stewart\Runtime\Dispatch\RegisteredSubscription;
use Stewart\Runtime\Dispatch\SelectorIndex;
use Stewart\Runtime\Dispatch\SubscriptionListener;
use Stewart\Runtime\Dispatch\SubscriptionQueue;
use Stewart\Runtime\Model\Collection\SubscriptionIdCollection;
use Stewart\Runtime\Model\ResourceScope;
use Stewart\Runtime\Model\SubscriptionId;
use Stewart\Runtime\Model\SubscriptionKind;
use Stewart\Runtime\Registry\RegistryCache;
use Stewart\Runtime\Scope\ScopeLifecycle;
use Stewart\Runtime\Tests\Fixtures\Worker\RecordingDispatchListener;
use Stewart\Testing\Async\Latch;
use Stewart\Testing\Time\EventLoopTicks;

#[CoversClass(LocalDispatcher::class)]
#[CoversClass(SubscriptionQueue::class)]
#[CoversClass(LocalSubscription::class)]
#[CoversClass(SelectorIndex::class)]
#[CoversClass(EntityFilterIndex::class)]
#[CoversClass(RegisteredSubscription::class)]
final class LocalDispatcherTest extends TestCase
{
    private RecordingDispatchListener $listener;

    private ScopeLifecycle $scopes;

    private RegistryCache $registry;

    protected function setUp(): void
    {
        $this->listener = new RecordingDispatchListener();
        $this->scopes = new ScopeLifecycle();
        $this->registry = new RegistryCache();
    }

    public function testRefusedRegistrationLeavesNothingBehind(): void
    {
        $refusing = new class implements SubscriptionListener {
            public function subscriptionRegistered(RegisteredSubscription $subscription): void
            {
                throw new RuntimeException('broker gone');
            }

            public function subscriptionCancelled(RegisteredSubscription $subscription): void {}
        };
        $dispatcher = new LocalDispatcher('w0', 100, $this->listener, $refusing, $this->scopes, $this->registry);
        $scope = new SubscriptionScope();

        try {
            $dispatcher->register(self::createScope('app'), SubscriptionKind::Event, Selector::exact('zha_event'), $scope, static fn(): null => null);
            self::fail('The refusal must surface to the subscriber.');
        } catch (RuntimeException) {
        }

        self::assertFalse($scope->isActive(), 'The scope is closed, so nothing stays wired to the orphaned queue.');
        self::assertSame(0, $dispatcher->countFor(self::createScope('app')));
    }

    public function testStateChangeReachesMatchingSubscriptions(): void
    {
        $dispatcher = $this->createDispatcher();
        $seen = [];

        $this->register($dispatcher, 'app', SubscriptionKind::StateChange, Selector::exact('light.hall'), static function (StateChange $change) use (&$seen): void {
            $seen[] = 'hall:' . $change->entityId;
        });
        $this->register($dispatcher, 'app', SubscriptionKind::StateChange, Selector::exact('light.porch'), static function (StateChange $change) use (&$seen): void {
            $seen[] = 'porch:' . $change->entityId;
        });

        $this->register($dispatcher, 'app', SubscriptionKind::StateChange, Selector::fromSpec('light.*'), static function (StateChange $change) use (&$seen): void {
            $seen[] = 'lights:' . $change->entityId;
        });

        $dispatcher->dispatchStateChange(self::createChange('light.hall'));
        EventLoopTicks::settleUntil(static function () use (&$seen): bool {
            return \count($seen) === 2;
        });

        self::assertEqualsCanonicalizing(['hall:light.hall', 'lights:light.hall'], $seen);
        self::assertSame(['w0:0', 'w0:1', 'w0:2'], $this->listener->registered);
    }

    public function testEntityFilterFollowsRegistryRevision(): void
    {
        $dispatcher = $this->createDispatcher();
        $seen = [];
        $this->registry->replaceIfNewer(self::createRegistryPlacingHallIn('kitchen'), 1);

        $this->register($dispatcher, 'app', SubscriptionKind::StateChange, Selector::exact('light.hall'), static function (StateChange $change) use (&$seen): void {
            $seen[] = 'selector:' . $change->entityId;
        });
        $this->register($dispatcher, 'app', SubscriptionKind::StateChange, Selector::any(), static function (StateChange $change) use (&$seen): void {
            $seen[] = 'kitchen:' . $change->entityId;
        }, entityFilter: EntityFilter::inArea('kitchen'));

        $dispatcher->dispatchStateChange(self::createChange('light.hall'));
        $dispatcher->dispatchStateChange(self::createChange('light.porch'));
        EventLoopTicks::settle();

        self::assertEqualsCanonicalizing(['selector:light.hall', 'kitchen:light.hall'], $seen);

        $this->registry->replaceIfNewer(self::createRegistryPlacingHallIn('hall'), 2);
        $dispatcher->dispatchStateChange(self::createChange('light.hall'));
        EventLoopTicks::settle();

        self::assertSame(['selector:light.hall'], \array_slice($seen, 2), 'The cached match is dropped with the old registry.');
    }

    public function testCancelledEntityFilterNoLongerMatches(): void
    {
        $dispatcher = $this->createDispatcher();
        $seen = 0;

        $subscription = $this->register($dispatcher, 'app', SubscriptionKind::StateChange, Selector::any(), static function () use (&$seen): void {
            ++$seen;
        }, entityFilter: EntityFilter::inDomain('light'));
        $subscription->unsubscribe();

        $dispatcher->dispatchStateChange(self::createChange('light.hall'));
        EventLoopTicks::settle();

        self::assertSame(0, $seen);
    }

    public function testEntityFilterIsOnlyForStateChanges(): void
    {
        $this->expectException(LogicException::class);

        $this->register($this->createDispatcher(), 'app', SubscriptionKind::Event, Selector::any(), static function (): void {}, entityFilter: EntityFilter::inDomain('light'));
    }

    public function testStateSubscriptionMatchesOnceRegistered(): void
    {
        $dispatcher = $this->createDispatcher();
        $seen = [];

        $this->register($dispatcher, 'app', SubscriptionKind::StateChange, Selector::exact('light.hall'), static function (StateChange $change) use (&$seen): void {
            $seen[] = $change->entityId->value;
        });
        $dispatcher->dispatchStateChange(self::createChange('light.hall'));
        EventLoopTicks::settleUntil(static function () use (&$seen): bool {
            return \count($seen) === 1;
        });

        self::assertSame(['light.hall'], $seen);
    }

    public function testCancelledStateSubscriptionNoLongerMatches(): void
    {
        $dispatcher = $this->createDispatcher();
        $seen = 0;

        $subscription = $this->register($dispatcher, 'app', SubscriptionKind::StateChange, Selector::exact('light.hall'), static function () use (&$seen): void {
            ++$seen;
        });
        $subscription->unsubscribe();

        $dispatcher->dispatchStateChange(self::createChange('light.hall'));
        EventLoopTicks::settle();

        self::assertSame(0, $seen);
    }

    public function testStateChangeSkipsOtherKinds(): void
    {
        $dispatcher = $this->createDispatcher();
        $called = false;

        $this->register($dispatcher, 'app', SubscriptionKind::Topic, Selector::any(), static function () use (&$called): void {
            $called = true;
        });

        $dispatcher->dispatchStateChange(self::createChange('light.hall'));
        EventLoopTicks::settle();

        self::assertFalse($called);
    }

    public function testHomeAssistantEventsReachOnlyEventSubscriptions(): void
    {
        $dispatcher = $this->createDispatcher();
        $seen = [];

        $zha = $this->register($dispatcher, 'app', SubscriptionKind::Event, Selector::exact('zha_event'), static function (HaEvent $event) use (&$seen): void {
            $seen[] = $event->type;
        });
        $state = $this->register($dispatcher, 'app', SubscriptionKind::StateChange, Selector::any(), static function () use (&$seen): void {
            $seen[] = 'state';
        });

        $dispatcher->dispatchEvent(new HaEvent('zha_event', ['command' => 'toggle']), self::listDeliveryTargets($zha, $state));
        EventLoopTicks::settle();

        self::assertSame(['zha_event'], $seen);
    }

    public function testTriggersReachOnlyTriggerSubscriptions(): void
    {
        $dispatcher = $this->createDispatcher();
        $seen = [];

        $scope = new SubscriptionScope();
        $sunset = $dispatcher->register(self::createScope('app'), SubscriptionKind::Trigger, Selector::exact('sunset-key'), $scope, static function (TriggerEvent $event) use (&$seen): void {
            $seen[] = $event->getPlatform();
        }, TriggerSpec::fromSpec(HaTrigger::onSunset()));
        $scope->attachedTo($sunset);
        $events = $this->register($dispatcher, 'app', SubscriptionKind::Event, Selector::any(), static function () use (&$seen): void {
            $seen[] = 'event';
        });

        $dispatcher->dispatchTrigger(new TriggerEvent(['platform' => 'sun']), self::listDeliveryTargets($sunset, $events));
        EventLoopTicks::settle();

        self::assertSame(['sun'], $seen);
    }

    public function testTriggerKindAndSpecMustComeTogether(): void
    {
        $dispatcher = $this->createDispatcher();
        $spec = TriggerSpec::fromSpec(HaTrigger::onSunset());

        try {
            $dispatcher->register(self::createScope('app'), SubscriptionKind::Trigger, Selector::any(), new SubscriptionScope(), static function (): void {});
            self::fail('A trigger subscription without a spec was accepted.');
        } catch (LogicException) {
        }

        $this->expectException(LogicException::class);

        $dispatcher->register(self::createScope('app'), SubscriptionKind::Event, Selector::any(), new SubscriptionScope(), static function (): void {}, $spec);
    }

    public function testReconstructedChangeUsesLivePath(): void
    {
        $dispatcher = $this->createDispatcher();
        $all = [];
        $liveOnly = [];

        $this->register($dispatcher, 'all', SubscriptionKind::StateChange, Selector::exact('light.hall'), static function (StateChange $change) use (&$all): void {
            $all[] = $change->origin;
        });
        $this->register($dispatcher, 'live', SubscriptionKind::StateChange, Selector::exact('light.hall'), static function (StateChange $change) use (&$liveOnly): void {
            if (!$change->isReconstructed()) {
                $liveOnly[] = $change->origin;
            }
        });

        $dispatcher->dispatchStateChange(new StateChange(new EntityId('light.hall'), null, new EntityState(new EntityId('light.hall'), 'on'), origin: StateChangeOrigin::Resync));
        $dispatcher->dispatchStateChange(self::createChange('light.hall'));
        EventLoopTicks::settleUntil(static function () use (&$all, &$liveOnly): bool {
            return \count($all) === 2 && \count($liveOnly) === 1;
        });

        self::assertSame([StateChangeOrigin::Resync, StateChangeOrigin::Live], $all);
        self::assertSame([StateChangeOrigin::Live], $liveOnly);
    }

    public function testConnectionEventsReachEverySubscriber(): void
    {
        $dispatcher = $this->createDispatcher();
        $count = 0;

        foreach (['one', 'two'] as $app) {
            $this->register($dispatcher, $app, SubscriptionKind::Connection, Selector::any(), static function (ConnectionEvent $event) use (&$count): void {
                ++$count;
            });
        }

        $dispatcher->dispatchConnection(new ConnectionRestored(Instant::fromEpochMicroseconds(0), 1, Duration::seconds(2.0), 0));
        EventLoopTicks::settleUntil(static function () use (&$count): bool {
            return $count === 2;
        });

        self::assertSame(2, $count);
    }

    public function testUnsubscribingStopsDeliveryAndTellsTheListener(): void
    {
        $dispatcher = $this->createDispatcher();
        $count = 0;

        $subscription = $this->register($dispatcher, 'app', SubscriptionKind::Topic, Selector::any(), static function () use (&$count): void {
            ++$count;
        });

        $subscription->unsubscribe();
        $dispatcher->dispatchTopic(new TopicEvent('t', null, new AppId('x'), Instant::fromEpochMicroseconds(0)), self::listDeliveryTargets($subscription));
        EventLoopTicks::settle();

        self::assertSame(0, $count);
        self::assertFalse($subscription->isActive());
        self::assertSame([$subscription->getId()], $this->listener->cancelled);
    }

    public function testReleasingAnAppLeavesOtherAppsSubscribed(): void
    {
        $dispatcher = $this->createDispatcher();
        $noop = static function (): void {};

        $this->register($dispatcher, 'gone', SubscriptionKind::Topic, Selector::any(), $noop);
        $this->register($dispatcher, 'gone', SubscriptionKind::Connection, Selector::any(), $noop);
        $this->register($dispatcher, 'stays', SubscriptionKind::Topic, Selector::any(), $noop);

        $this->release($dispatcher, 'gone');

        self::assertSame(0, $dispatcher->countFor(self::createScope('gone')));
        self::assertSame(1, $dispatcher->countFor(self::createScope('stays')));
    }

    public function testReleasedAppCannotSubscribeAgain(): void
    {
        $dispatcher = $this->createDispatcher();
        $scope = new SubscriptionScope();

        $this->release($dispatcher, 'gone');

        $subscription = $dispatcher->register(self::createScope('gone'), SubscriptionKind::Topic, Selector::any(), $scope, static function (): void {});

        self::assertFalse($subscription->isActive());
        self::assertFalse($scope->isActive(), 'Operator teardowns on the scope must run.');
        self::assertSame(0, $dispatcher->countFor(self::createScope('gone')));
        self::assertSame([], $this->listener->registered, 'Nothing is announced to the broker.');
    }

    public function testNothingCanSubscribeOnceEverythingIsReleased(): void
    {
        $dispatcher = $this->createDispatcher();

        $this->releaseEverything($dispatcher);

        $subscription = $this->register($dispatcher, 'late', SubscriptionKind::Topic, Selector::any(), static function (): void {});

        self::assertFalse($subscription->isActive());
        self::assertSame(0, $dispatcher->countFor(self::createScope('late')));
    }

    public function testThrowingHandlerIsReportedAndKeepsReceiving(): void
    {
        $dispatcher = $this->createDispatcher();
        $calls = 0;

        $subscription = $this->register($dispatcher, 'app', SubscriptionKind::Topic, Selector::any(), static function () use (&$calls): void {
            ++$calls;

            throw new RuntimeException('boom');
        });

        $dispatcher->dispatchTopic(new TopicEvent('t', 1, new AppId('x'), Instant::fromEpochMicroseconds(0)), self::listDeliveryTargets($subscription));
        $dispatcher->dispatchTopic(new TopicEvent('t', 2, new AppId('x'), Instant::fromEpochMicroseconds(0)), self::listDeliveryTargets($subscription));
        EventLoopTicks::settleUntil(function () use (&$calls): bool {
            return $calls === 2 && \count($this->listener->failures) === 2;
        });

        self::assertSame(2, $calls);
        self::assertCount(2, $this->listener->failures);
        self::assertSame([], $this->listener->delivered);
    }

    public function testSubscriptionHandlesEventsSequentially(): void
    {
        $dispatcher = $this->createDispatcher();
        $log = [];
        $slow = new Latch();

        $subscription = $this->register($dispatcher, 'app', SubscriptionKind::Topic, Selector::any(), static function (TopicEvent $event) use (&$log, $slow): void {
            $number = \is_int($event->payload) ? $event->payload : 0;
            $log[] = 'start ' . $number;

            if ($number === 1) {
                $slow->waitUntilOpen();
            }

            $log[] = 'end ' . $number;
        });

        $dispatcher->dispatchTopic(new TopicEvent('t', 1, new AppId('x'), Instant::fromEpochMicroseconds(0)), self::listDeliveryTargets($subscription));
        $dispatcher->dispatchTopic(new TopicEvent('t', 2, new AppId('x'), Instant::fromEpochMicroseconds(0)), self::listDeliveryTargets($subscription));
        EventLoopTicks::settle();

        self::assertSame(['start 1'], $log);

        $slow->open();
        EventLoopTicks::settleUntil(static function () use (&$log): bool {
            return \count($log) === 4;
        });

        self::assertSame(['start 1', 'end 1', 'start 2', 'end 2'], $log);
    }

    public function testBusyHandlerDropsItsOldestQueuedEvents(): void
    {
        $dispatcher = $this->createDispatcher(queueLimit: 2);
        $handled = [];

        $subscription = $this->register($dispatcher, 'app', SubscriptionKind::Topic, Selector::any(), static function (TopicEvent $event) use (&$handled): void {
            $handled[] = $event->payload;
        });

        foreach ([1, 2, 3, 4] as $payload) {
            $dispatcher->dispatchTopic(new TopicEvent('t', $payload, new AppId('x'), Instant::fromEpochMicroseconds(0)), self::listDeliveryTargets($subscription));
        }

        EventLoopTicks::settleUntil(static function () use (&$handled): bool {
            return \count($handled) === 2;
        });

        self::assertSame([3, 4], $handled);
        self::assertSame([1, 2], $this->listener->dropReports);
    }

    public function testEventsForADormantAppWaitAndThenRunInOrder(): void
    {
        $dispatcher = $this->createDispatcher();
        $handled = [];

        $subscription = $this->register($dispatcher, 'app', SubscriptionKind::Topic, Selector::any(), static function (TopicEvent $event) use (&$handled): void {
            $handled[] = $event->payload;
        }, activate: false);

        foreach ([1, 2] as $payload) {
            $dispatcher->dispatchTopic(new TopicEvent('t', $payload, new AppId('x'), Instant::fromEpochMicroseconds(0)), self::listDeliveryTargets($subscription));
        }

        EventLoopTicks::settle();

        self::assertSame([], $handled, 'Nothing of an app runs before it is activated.');

        $this->goLive($dispatcher, 'app');
        EventLoopTicks::settleUntil(static function () use (&$handled): bool {
            return \count($handled) === 2;
        });

        self::assertSame([1, 2], $handled);
    }

    public function testDormantQueueStillDropsItsOldestEvents(): void
    {
        $dispatcher = $this->createDispatcher(queueLimit: 2);
        $handled = [];

        $subscription = $this->register($dispatcher, 'app', SubscriptionKind::Topic, Selector::any(), static function (TopicEvent $event) use (&$handled): void {
            $handled[] = $event->payload;
        }, activate: false);

        foreach ([1, 2, 3, 4] as $payload) {
            $dispatcher->dispatchTopic(new TopicEvent('t', $payload, new AppId('x'), Instant::fromEpochMicroseconds(0)), self::listDeliveryTargets($subscription));
        }

        $this->goLive($dispatcher, 'app');
        EventLoopTicks::settleUntil(static function () use (&$handled): bool {
            return \count($handled) === 2;
        });

        self::assertSame([3, 4], $handled);
    }

    public function testOperatorEmissionIsNotEvictedByABusyQueue(): void
    {
        $dispatcher = $this->createDispatcher(queueLimit: 1);
        $handled = [];
        $scope = new SubscriptionScope();
        $busy = new Latch();

        $subscription = $dispatcher->register(self::createScope('app'), SubscriptionKind::Topic, Selector::any(), $scope, static function (TopicEvent $event) use (&$handled, $busy): void {
            $handled[] = $event->payload;
            $busy->waitUntilOpen();
        });
        $scope->attachedTo($subscription);
        $this->goLive($dispatcher, 'app');

        $dispatcher->dispatchTopic(new TopicEvent('t', 'first', new AppId('x'), Instant::fromEpochMicroseconds(0)), self::listDeliveryTargets($subscription));
        EventLoopTicks::settleUntil(static function () use (&$handled): bool {
            return \count($handled) === 1;
        });

        $scope->emit(static function () use (&$handled): void {
            $handled[] = 'emission';
        });
        $dispatcher->dispatchTopic(new TopicEvent('t', 'last', new AppId('x'), Instant::fromEpochMicroseconds(0)), self::listDeliveryTargets($subscription));
        EventLoopTicks::settle();

        self::assertSame(['first'], $handled, 'The handler is still busy with the first event.');

        $busy->open();
        EventLoopTicks::settleUntil(static function () use (&$handled): bool {
            return \count($handled) === 3;
        });

        self::assertSame(['first', 'emission', 'last'], $handled, 'A timer emission is not a droppable raw event.');
    }

    public function testCancelDormantSubscriptionDiscardsQueue(): void
    {
        $dispatcher = $this->createDispatcher();
        $handled = 0;

        $subscription = $this->register($dispatcher, 'app', SubscriptionKind::Topic, Selector::any(), static function () use (&$handled): void {
            ++$handled;
        }, activate: false);

        $dispatcher->dispatchTopic(new TopicEvent('t', 1, new AppId('x'), Instant::fromEpochMicroseconds(0)), self::listDeliveryTargets($subscription));
        $subscription->unsubscribe();
        $this->goLive($dispatcher, 'app');
        EventLoopTicks::settle();

        self::assertSame(0, $handled);
    }

    public function testRegistrationAfterActivationIsLive(): void
    {
        $dispatcher = $this->createDispatcher();
        $handled = 0;

        $this->goLive($dispatcher, 'app');

        $subscription = $this->register($dispatcher, 'app', SubscriptionKind::Topic, Selector::any(), static function () use (&$handled): void {
            ++$handled;
        }, activate: false);

        $dispatcher->dispatchTopic(new TopicEvent('t', 1, new AppId('x'), Instant::fromEpochMicroseconds(0)), self::listDeliveryTargets($subscription));
        EventLoopTicks::settleUntil(static function () use (&$handled): bool {
            return $handled === 1;
        });

        self::assertSame(1, $handled);
    }

    public function testDormantAppGetsBufferedConnectionEvents(): void
    {
        $dispatcher = $this->createDispatcher();
        /** @var list<class-string> $seen */
        $seen = [];

        $this->register($dispatcher, 'app', SubscriptionKind::Connection, Selector::any(), static function (object $event) use (&$seen): void {
            $seen[] = $event::class;
        }, activate: false);

        $dispatcher->dispatchConnection(new ConnectionLost(Instant::fromEpochMicroseconds(0), 'gone'));
        $this->goLive($dispatcher, 'app');
        $dispatcher->dispatchConnection(new ConnectionRestored(Instant::fromEpochMicroseconds(1), 0, Duration::milliseconds(1), 0));
        EventLoopTicks::settleUntil(static function () use (&$seen): bool {
            return \count($seen) === 2;
        });

        self::assertSame([ConnectionLost::class, ConnectionRestored::class], $seen);
    }

    public function testCountsPerScopeAndReportsDrops(): void
    {
        $dispatcher = $this->createDispatcher(queueLimit: 1);

        $live = $this->register($dispatcher, 'app', SubscriptionKind::StateChange, Selector::exact('light.hall'), static function (): void {});
        $dormant = $this->register($dispatcher, 'other', SubscriptionKind::Topic, Selector::exact('house.mode'), static function (): void {}, activate: false);

        $dispatcher->dispatchStateChange(self::createChange('light.hall'));
        EventLoopTicks::settleUntil(fn(): bool => \count($this->listener->delivered) === 1);

        foreach (['a', 'b', 'c'] as $payload) {
            $dispatcher->dispatchTopic(new TopicEvent('house.mode', $payload, new AppId('demo'), Instant::fromEpochMicroseconds(0)), self::listDeliveryTargets($dormant));
        }

        self::assertSame(1, $dispatcher->countFor(self::createScope('app')));
        self::assertSame(1, $dispatcher->countFor(self::createScope('other')));
        self::assertSame(0, $dispatcher->countFor(self::createScope('nobody')));
        self::assertSame([$live->getId()], $this->listener->delivered);
        self::assertSame([1, 2], $this->listener->dropReports, 'The listener sees every drop and decides how often to log.');
    }

    public function testPausedAppSuppressesItsEvents(): void
    {
        $dispatcher = $this->createDispatcher();
        $handled = [];

        $paused = $this->register($dispatcher, 'app', SubscriptionKind::Topic, Selector::any(), static function (TopicEvent $event) use (&$handled): void {
            $handled[] = ['app', $event->payload];
        });
        $other = $this->register($dispatcher, 'other', SubscriptionKind::Topic, Selector::any(), static function (TopicEvent $event) use (&$handled): void {
            $handled[] = ['other', $event->payload];
        });
        $this->pause($dispatcher, 'app');

        $dispatcher->dispatchTopic(new TopicEvent('t', 1, new AppId('x'), Instant::fromEpochMicroseconds(0)), self::listDeliveryTargets($paused, $other));
        EventLoopTicks::settleUntil(static function () use (&$handled): bool {
            return \count($handled) === 1;
        });

        self::assertSame([['other', 1]], $handled);
        self::assertSame([$paused->getId()], $this->listener->suppressed);
    }

    public function testPauseDiscardsQueuedEvents(): void
    {
        $dispatcher = $this->createDispatcher();
        $handled = 0;

        $subscription = $this->register($dispatcher, 'app', SubscriptionKind::Topic, Selector::any(), static function () use (&$handled): void {
            ++$handled;
        }, activate: false);

        foreach ([1, 2] as $payload) {
            $dispatcher->dispatchTopic(new TopicEvent('t', $payload, new AppId('x'), Instant::fromEpochMicroseconds(0)), self::listDeliveryTargets($subscription));
        }

        $this->pause($dispatcher, 'app');
        $this->goLive($dispatcher, 'app');
        $this->resume($dispatcher, 'app');
        EventLoopTicks::settle();

        self::assertSame(0, $handled, 'Events queued before the pause are not replayed on resume.');
        self::assertCount(2, $this->listener->suppressed);
    }

    public function testResumedAppReceivesOnlyNewEvents(): void
    {
        $dispatcher = $this->createDispatcher();
        $handled = [];

        $subscription = $this->register($dispatcher, 'app', SubscriptionKind::Topic, Selector::any(), static function (TopicEvent $event) use (&$handled): void {
            $handled[] = $event->payload;
        });
        $this->pause($dispatcher, 'app');
        $dispatcher->dispatchTopic(new TopicEvent('t', 'missed', new AppId('x'), Instant::fromEpochMicroseconds(0)), self::listDeliveryTargets($subscription));

        $this->resume($dispatcher, 'app');
        $dispatcher->dispatchTopic(new TopicEvent('t', 'fresh', new AppId('x'), Instant::fromEpochMicroseconds(0)), self::listDeliveryTargets($subscription));
        EventLoopTicks::settleUntil(static function () use (&$handled): bool {
            return \count($handled) === 1;
        });

        self::assertSame(['fresh'], $handled);
    }

    public function testPausedAppSuppressesStreamEmissions(): void
    {
        $dispatcher = $this->createDispatcher();
        $handled = 0;
        $scope = new SubscriptionScope();

        $subscription = $dispatcher->register(self::createScope('app'), SubscriptionKind::Topic, Selector::any(), $scope, static fn(): null => null);
        $scope->attachedTo($subscription);
        $this->goLive($dispatcher, 'app');
        $this->pause($dispatcher, 'app');

        $scope->emit(static function () use (&$handled): void {
            ++$handled;
        });
        EventLoopTicks::settle();

        self::assertSame(0, $handled);
        self::assertSame([$subscription->getId()], $this->listener->suppressed);
    }

    public function testSubscriptionOfPausedAppStartsPaused(): void
    {
        $dispatcher = $this->createDispatcher();
        $handled = 0;
        $this->pause($dispatcher, 'app');

        $subscription = $this->register($dispatcher, 'app', SubscriptionKind::Topic, Selector::any(), static function () use (&$handled): void {
            ++$handled;
        });

        $dispatcher->dispatchTopic(new TopicEvent('t', 1, new AppId('x'), Instant::fromEpochMicroseconds(0)), self::listDeliveryTargets($subscription));
        EventLoopTicks::settle();

        self::assertSame(0, $handled);
        self::assertSame([$subscription->getId()], $this->listener->suppressed);
    }

    private function createDispatcher(int $queueLimit = 100): LocalDispatcher
    {
        return new LocalDispatcher('w0', $queueLimit, $this->listener, $this->listener, $this->scopes, $this->registry);
    }

    private function pause(LocalDispatcher $dispatcher, string $appId): void
    {
        $this->scopes->pauseScope(self::createScope($appId));
        $dispatcher->pauseQueuesOf(self::createScope($appId));
    }

    private function resume(LocalDispatcher $dispatcher, string $appId): void
    {
        $this->scopes->resumeScope(self::createScope($appId));
        $dispatcher->resumeQueuesOf(self::createScope($appId));
    }

    private function goLive(LocalDispatcher $dispatcher, string $appId): void
    {
        $this->scopes->activateScope(self::createScope($appId));
        $dispatcher->activateQueuesOf(self::createScope($appId));
    }

    private function release(LocalDispatcher $dispatcher, string $appId): void
    {
        $this->scopes->releaseScope(self::createScope($appId));
        $dispatcher->cancelSubscriptionsOf(self::createScope($appId));
    }

    private function releaseEverything(LocalDispatcher $dispatcher): void
    {
        $this->scopes->stopAll();
        $dispatcher->cancelAll();
    }

    private static function createRegistryPlacingHallIn(string $areaId): IndexedRegistry
    {
        return IndexedRegistry::fromParts(
            AreaCollection::empty(),
            FloorCollection::empty(),
            LabelCollection::empty(),
            DeviceCollection::empty(),
            RegisteredEntityCollection::keyedByEntityId([new RegisteredEntity(new EntityId('light.hall'), areaId: new AreaId($areaId))]),
        );
    }

    private static function createChange(string $entityId): StateChange
    {
        return new StateChange(new EntityId($entityId), null, new EntityState(new EntityId($entityId), 'on'));
    }

    /**
     * @template TEvent
     * @param Closure(TEvent): void $handler
     */
    private function register(
        LocalDispatcher $dispatcher,
        string $appId,
        SubscriptionKind $kind,
        Selector $selector,
        Closure $handler,
        bool $activate = true,
        ?EntityFilter $entityFilter = null,
    ): Subscription {
        $scope = new SubscriptionScope();
        $subscription = $dispatcher->register(self::createScope($appId), $kind, $selector, $scope, $handler, entityFilter: $entityFilter);
        $scope->attachedTo($subscription);

        if ($activate) {
            $this->goLive($dispatcher, $appId);
        }

        return $subscription;
    }

    private static function createScope(string $appId): ResourceScope
    {
        return ResourceScope::forApp(new AppId($appId));
    }

    private static function listDeliveryTargets(Subscription ...$subscriptions): SubscriptionIdCollection
    {
        return SubscriptionIdCollection::fromIds(array_map(static fn(Subscription $subscription): SubscriptionId => SubscriptionId::fromString($subscription->getId()), $subscriptions));
    }
}
