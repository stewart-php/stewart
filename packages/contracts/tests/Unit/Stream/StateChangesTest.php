<?php

declare(strict_types=1);

namespace Stewart\Contracts\Tests\Unit\Stream;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\State\EntityState;
use Stewart\Contracts\State\StateChange;
use Stewart\Contracts\StateChangeStream;
use Stewart\Contracts\Stream\ComposedStateChangeStream;
use Stewart\Contracts\Stream\DebounceOperator;
use Stewart\Contracts\Stream\DistinctUntilChangedOperator;
use Stewart\Contracts\Stream\StateChanges;
use Stewart\Contracts\Stream\ThrottleOperator;
use Stewart\Contracts\Stream\WhenChangedToOperator;
use Stewart\Contracts\Stream\WhenStableForOperator;
use Stewart\Contracts\Time\Duration;
use Stewart\Testing\Stream\PushSource;
use Stewart\Testing\Time\ManualTimers;

#[CoversClass(StateChanges::class)]
#[CoversClass(ComposedStateChangeStream::class)]
#[CoversClass(DistinctUntilChangedOperator::class)]
#[CoversClass(DebounceOperator::class)]
#[CoversClass(ThrottleOperator::class)]
#[CoversClass(WhenChangedToOperator::class)]
#[CoversClass(WhenStableForOperator::class)]
final class StateChangesTest extends TestCase
{
    private const string ENTITY = 'binary_sensor.hall_motion';

    private const string OTHER = 'binary_sensor.porch_motion';

    private ManualTimers $timers;

    /** @var PushSource<StateChange> */
    private PushSource $source;

    private StateChanges $stream;

    /** @var list<string> */
    private array $received = [];

    protected function setUp(): void
    {
        $this->timers = new ManualTimers();
        $this->source = new PushSource();
        $this->stream = new StateChanges($this->source, $this->timers);
        $this->received = [];
    }

    public function testWhenChangedToPassesOnlyArrivals(): void
    {
        $this->listen($this->stream->whenChangedTo('on'));

        $this->push($this->createChange('off', 'on'));
        $this->push($this->createChange('on', 'on'));
        $this->push($this->createChange('on', 'off'));
        $this->push($this->createChange('off', 'on'));

        self::assertSame(['on', 'on'], $this->received);
    }

    public function testWhenChangedToSkipsUnavailablePrevious(): void
    {
        $this->listen($this->stream->whenChangedTo('on'));

        $this->push($this->createChange(EntityState::UNAVAILABLE, 'on'));
        $this->push($this->createChange(EntityState::UNKNOWN, 'on'));
        $this->push($this->createChange(null, 'on'));

        self::assertSame([], $this->received);
    }

    public function testSkippedArrivalDoesNotArmWindow(): void
    {
        $this->listen($this->stream->whenChangedTo('on', for: Duration::seconds(3)));

        $this->push($this->createChange(EntityState::UNAVAILABLE, 'on'));

        self::assertSame(0, $this->timers->countPendingTimers());
    }

    public function testReturnFromUnavailableStaysQuiet(): void
    {
        $this->listen($this->stream->whenChangedTo('on', for: Duration::seconds(3)));

        $this->push($this->createChange('off', 'on'));
        $this->push($this->createChange('on', EntityState::UNAVAILABLE));
        $this->push($this->createChange(EntityState::UNAVAILABLE, 'on'));
        $this->timers->delay(Duration::seconds(5));

        self::assertSame([], $this->received);
    }

    public function testWhenStableForEmitsAfterWindow(): void
    {
        $this->listen($this->stream->whenStableFor(Duration::minutes(5)));

        $this->push($this->createChange('off', 'on'));

        $this->timers->delay(Duration::minutes(4));
        self::assertSame([], $this->received);

        $this->timers->delay(Duration::minutes(1));
        self::assertSame(['on'], $this->received);
    }

    public function testAttributeOnlyUpdateKeepsHold(): void
    {
        $this->listen($this->stream->whenStableFor(Duration::minutes(5)));

        $this->push($this->createChange('off', 'on'));

        // HA republishes attributes constantly; re-arming on them would starve a long hold.
        $this->timers->delay(Duration::minutes(4));
        $this->push($this->createChange('on', 'on', ['battery' => 91]));
        $this->push($this->createChange('on', 'on', ['battery' => 90]));

        $this->timers->delay(Duration::minutes(1));

        self::assertSame(['on'], $this->received);
    }

    public function testDifferentStateRestartsHold(): void
    {
        $this->listen($this->stream->whenStableFor(Duration::minutes(5)));

        $this->push($this->createChange('off', 'on'));
        $this->timers->delay(Duration::minutes(3));

        $this->push($this->createChange('on', 'off'));
        $this->timers->delay(Duration::minutes(3));

        self::assertSame([], $this->received);

        $this->timers->delay(Duration::minutes(2));
        self::assertSame(['off'], $this->received);
    }

    public function testFiredHoldIsNotRearmedBySameState(): void
    {
        $this->listen($this->stream->whenStableFor(Duration::minutes(5)));

        $this->push($this->createChange('off', 'on'));
        $this->timers->delay(Duration::minutes(5));
        self::assertSame(['on'], $this->received);

        $this->push($this->createChange('on', 'on'));
        $this->timers->delay(Duration::minutes(10));

        self::assertSame(['on'], $this->received);
    }

    public function testHoldRearmsBehindSingleStateFilter(): void
    {
        // whenChangedTo() strips "off", so each same-state event must still count as a fresh arrival.
        $this->listen($this->stream->whenChangedTo('on')->whenStableFor(Duration::seconds(3)));

        $this->push($this->createChange('off', 'on'));
        $this->timers->delay(Duration::seconds(3));
        self::assertSame(['on'], $this->received);

        $this->push($this->createChange('on', 'off'));
        $this->push($this->createChange('off', 'on'));
        $this->timers->delay(Duration::seconds(3));

        self::assertSame(['on', 'on'], $this->received);
    }

    public function testChangedToWithWindowFiresAfterHold(): void
    {
        $this->listen($this->stream->whenChangedTo('on', for: Duration::seconds(3)));

        $this->push($this->createChange('off', 'on'));

        $this->timers->delay(Duration::seconds(2));
        self::assertSame([], $this->received);

        $this->timers->delay(Duration::seconds(1));
        self::assertSame(['on'], $this->received);
    }

    public function testChangedToWithWindowQuietOnEarlyLeave(): void
    {
        $this->listen($this->stream->whenChangedTo('on', for: Duration::seconds(3)));

        $this->push($this->createChange('off', 'on'));
        $this->timers->delay(Duration::seconds(2));

        $this->push($this->createChange('on', 'off'));
        $this->timers->delay(Duration::seconds(5));

        self::assertSame([], $this->received);
    }

    public function testAttributeChurnDoesNotDisturbTheWindow(): void
    {
        $this->listen($this->stream->whenChangedTo('on', for: Duration::seconds(3)));

        $this->push($this->createChange('off', 'on'));

        $this->timers->delay(Duration::seconds(2));
        $this->push($this->createChange('on', 'on', ['battery' => 90]));

        $this->timers->delay(Duration::seconds(1));

        self::assertSame(['on'], $this->received);
    }

    public function testUnavailableAbandonsWindow(): void
    {
        $this->listen($this->stream->whenChangedTo('on', for: Duration::seconds(3)));

        $this->push($this->createChange('off', 'on'));
        $this->timers->delay(Duration::seconds(1));

        $this->push($this->createChange('on', 'unavailable'));
        $this->timers->delay(Duration::seconds(5));

        self::assertSame([], $this->received);
    }

    public function testEachFreshArrivalGetsItsOwnWindow(): void
    {
        $this->listen($this->stream->whenChangedTo('on', for: Duration::seconds(3)));

        $this->push($this->createChange('off', 'on'));
        $this->timers->delay(Duration::seconds(3));

        $this->push($this->createChange('on', 'off'));
        $this->push($this->createChange('off', 'on'));
        $this->timers->delay(Duration::seconds(3));

        self::assertSame(['on', 'on'], $this->received);
    }

    public function testUnsubscribeCancelsRunningWindow(): void
    {
        $subscription = $this->stream->whenChangedTo('on', for: Duration::hours(1))->subscribe(static fn() => null);

        $this->push($this->createChange('off', 'on'));
        self::assertSame(1, $this->timers->countPendingTimers());

        $subscription->unsubscribe();

        self::assertSame(0, $this->timers->countPendingTimers());
    }

    public function testFilterBeforeHoldHidesDepartures(): void
    {
        // Filtering first removes the departure, so the hold never sees it.
        $this->listen($this->stream->whenChangedTo('on')->whenStableFor(Duration::seconds(3)));

        $this->push($this->createChange('off', 'on'));
        $this->timers->delay(Duration::seconds(2));
        $this->push($this->createChange('on', 'off'));
        $this->timers->delay(Duration::seconds(5));

        self::assertSame(['on'], $this->received);
    }

    public function testHoldBeforeFilterSeesDeparture(): void
    {
        $this->listen($this->stream->whenStableFor(Duration::seconds(3))->whenChangedTo('on'));

        $this->push($this->createChange('off', 'on'));
        $this->timers->delay(Duration::seconds(2));
        $this->push($this->createChange('on', 'off'));
        $this->timers->delay(Duration::seconds(5));

        self::assertSame([], $this->received);
    }

    public function testHoldBeforeFilterFiresWhenStateSticks(): void
    {
        $this->listen($this->stream->whenStableFor(Duration::seconds(3))->whenChangedTo('on'));

        $this->push($this->createChange('off', 'on'));
        $this->timers->delay(Duration::seconds(3));

        self::assertSame(['on'], $this->received);
    }

    public function testRemovalArmsHold(): void
    {
        $removals = [];
        $this->stream->whenStableFor(Duration::minutes(5))->subscribe(static function (StateChange $change) use (&$removals): void {
            $removals[] = $change->isRemoved();
        });

        // `to` is null here, so the operator must not treat a null state as "nothing held yet".
        $this->push(new StateChange(new EntityId(self::ENTITY), self::createState('on'), null));

        $this->timers->delay(Duration::minutes(5));

        self::assertSame([true], $removals);
    }

    public function testOtherEntityLeavingKeepsWindow(): void
    {
        $this->listenByEntity($this->stream->whenChangedTo('on', for: Duration::seconds(3)));

        $this->push($this->createChangeForEntity(self::OTHER, 'on', 'off'));
        $this->push($this->createChange('off', 'on'));
        $this->timers->delay(Duration::seconds(1));

        $this->push($this->createChangeForEntity(self::OTHER, 'off', 'off'));
        $this->timers->delay(Duration::seconds(2));

        self::assertSame([self::ENTITY . '=on'], $this->received);
    }

    public function testEntitiesFireAtOwnDeadlines(): void
    {
        $this->listenByEntity($this->stream->whenChangedTo('on', for: Duration::seconds(3)));

        $this->push($this->createChange('off', 'on'));
        $this->timers->delay(Duration::seconds(1));

        $this->push($this->createChangeForEntity(self::OTHER, 'off', 'on'));
        $this->timers->delay(Duration::seconds(2));

        self::assertSame([self::ENTITY . '=on'], $this->received);

        $this->timers->delay(Duration::seconds(1));

        self::assertSame([self::ENTITY . '=on', self::OTHER . '=on'], $this->received);
    }

    public function testWhenStableForHoldsEachEntitySeparately(): void
    {
        $this->listenByEntity($this->stream->whenStableFor(Duration::seconds(3)));

        $this->push($this->createChange('off', 'on'));
        $this->timers->delay(Duration::seconds(1));

        $this->push($this->createChangeForEntity(self::OTHER, 'off', 'on'));
        $this->timers->delay(Duration::seconds(2));

        self::assertSame([self::ENTITY . '=on'], $this->received);

        $this->timers->delay(Duration::seconds(1));

        self::assertSame([self::ENTITY . '=on', self::OTHER . '=on'], $this->received);
    }

    public function testOtherEntityChurnKeepsWindow(): void
    {
        $this->listenByEntity($this->stream->whenStableFor(Duration::seconds(3)));

        $this->push($this->createChange('off', 'on'));
        $this->timers->delay(Duration::seconds(2));

        $this->push($this->createChangeForEntity(self::OTHER, 'on', 'on', ['battery' => 90]));
        $this->timers->delay(Duration::seconds(1));

        self::assertSame([self::ENTITY . '=on'], $this->received);
    }

    public function testUnsubscribingCancelsEveryEntitysWindow(): void
    {
        $subscription = $this->stream->whenChangedTo('on', for: Duration::hours(1))->subscribe(static fn() => null);

        $this->push($this->createChange('off', 'on'));
        $this->push($this->createChangeForEntity(self::OTHER, 'off', 'on'));
        self::assertSame(2, $this->timers->countPendingTimers());

        $subscription->unsubscribe();

        self::assertSame(0, $this->timers->countPendingTimers());
    }

    public function testTakeBeforeChangedToWindowStillFires(): void
    {
        $this->listen($this->stream->take(1)->whenChangedTo('on', for: Duration::seconds(3)));

        $this->push($this->createChange('off', 'on'));
        $this->timers->delay(Duration::seconds(3));

        self::assertSame(['on'], $this->received);
        self::assertSame(0, $this->source->countSubscribers());
    }

    public function testTakeIgnoresWindowsBeforeIt(): void
    {
        $this->listenByEntity($this->stream->debounce(Duration::seconds(5))->take(1));

        $this->push($this->createChangeForEntity(self::ENTITY, 'off', 'on'));
        $this->timers->delay(Duration::seconds(3));
        $this->push($this->createChangeForEntity(self::OTHER, 'off', 'on'));
        $this->timers->delay(Duration::seconds(2));

        self::assertSame([self::ENTITY . '=on'], $this->received);
        self::assertSame(0, $this->source->countSubscribers(), 'The other entity can no longer get through take().');
        self::assertSame(0, $this->timers->countPendingTimers());
    }

    public function testDistinctUntilChangedUsesState(): void
    {
        $this->listen($this->stream->distinctUntilChanged());

        $this->push($this->createChange('off', 'on'));
        $this->push($this->createChange('on', 'on', ['battery' => 90]));
        $this->push($this->createChange('on', 'off'));

        self::assertSame(['on', 'off'], $this->received);
    }

    public function testDistinctUntilChangedPassesFirstRemoval(): void
    {
        $this->listen($this->stream->distinctUntilChanged());

        $this->push(new StateChange(new EntityId(self::ENTITY), self::createState('on'), null));
        $this->push(new StateChange(new EntityId(self::ENTITY), null, null));

        self::assertSame(['<removed>'], $this->received);
    }

    public function testDistinctTracksEachEntitySeparately(): void
    {
        $this->listenByEntity($this->stream->distinctUntilChanged());

        $this->push($this->createChangeForEntity(self::ENTITY, 'off', 'on'));
        $this->push($this->createChangeForEntity(self::OTHER, 'off', 'on'));
        $this->push($this->createChangeForEntity(self::ENTITY, 'on', 'on', ['battery' => 90]));
        $this->push($this->createChangeForEntity(self::OTHER, 'on', 'off'));

        self::assertSame([self::ENTITY . '=on', self::OTHER . '=on', self::OTHER . '=off'], $this->received);
    }

    public function testDebounceHoldsEachEntitySeparately(): void
    {
        $this->listenByEntity($this->stream->debounce(Duration::seconds(5)));

        $this->push($this->createChangeForEntity(self::ENTITY, 'off', 'on'));
        $this->timers->delay(Duration::seconds(3));
        $this->push($this->createChangeForEntity(self::OTHER, 'off', 'on'));
        $this->timers->delay(Duration::seconds(2));

        self::assertSame([self::ENTITY . '=on'], $this->received, 'Another entity must not restart this one\'s quiet period.');

        $this->timers->delay(Duration::seconds(3));

        self::assertSame([self::ENTITY . '=on', self::OTHER . '=on'], $this->received);
    }

    public function testThrottleOpensWindowPerEntity(): void
    {
        $this->listenByEntity($this->stream->throttle(Duration::seconds(5)));

        $this->push($this->createChangeForEntity(self::ENTITY, 'off', 'on'));
        $this->push($this->createChangeForEntity(self::OTHER, 'off', 'on'));
        $this->push($this->createChangeForEntity(self::ENTITY, 'on', 'off'));

        self::assertSame([self::ENTITY . '=on', self::OTHER . '=on'], $this->received);
    }

    public function testAcrossEntitiesDebouncesWholeStream(): void
    {
        $received = [];
        $this->stream->acrossEntities()->debounce(Duration::seconds(5))->subscribe(static function (StateChange $change) use (&$received): void {
            $received[] = $change->entityId->value;
        });

        $this->push($this->createChangeForEntity(self::ENTITY, 'off', 'on'));
        $this->push($this->createChangeForEntity(self::OTHER, 'off', 'on'));
        $this->timers->delay(Duration::seconds(5));

        self::assertSame([self::OTHER], $received);
    }

    public function testGenericOperatorsKeepStateChangeStream(): void
    {
        $chained = $this->stream
            ->filter(static fn(StateChange $change): bool => $change->entityId->value === self::ENTITY)
            ->debounce(Duration::milliseconds(100))
            ->whenChangedTo('on');

        self::assertInstanceOf(StateChangeStream::class, $chained);

        $this->listen($chained);

        $this->push($this->createChange('off', 'on'));
        $this->timers->delay(Duration::milliseconds(100));

        self::assertSame(['on'], $this->received);
    }

    public function testMapLeavesTheStateChangeFlavourBehind(): void
    {
        $names = [];
        $this->stream
            ->map(static fn(StateChange $change): string => $change->entityId->value)
            ->subscribe(static function (string $name) use (&$names): void {
                $names[] = $name;
            });

        $this->push($this->createChange('off', 'on'));

        self::assertSame([self::ENTITY], $names);
    }

    private function listen(StateChangeStream $stream): void
    {
        $stream->subscribe(function (StateChange $change): void {
            $this->received[] = $change->to === null ? '<removed>' : $change->to->state;
        });
    }

    private function listenByEntity(StateChangeStream $stream): void
    {
        $stream->subscribe(function (StateChange $change): void {
            $this->received[] = $change->entityId . '=' . ($change->to === null ? '<removed>' : $change->to->state);
        });
    }

    private function push(StateChange $change): void
    {
        $this->source->push($change);
    }

    /** @param array<string, mixed> $attributes */
    private function createChange(?string $from, string $to, array $attributes = []): StateChange
    {
        return $this->createChangeForEntity(self::ENTITY, $from, $to, $attributes);
    }

    /** @param array<string, mixed> $attributes */
    private function createChangeForEntity(string $entityId, ?string $from, string $to, array $attributes = []): StateChange
    {
        return new StateChange(
            new EntityId($entityId),
            $from === null ? null : self::createState($from, entityId: $entityId),
            self::createState($to, $attributes, $entityId),
        );
    }

    /** @param array<string, mixed> $attributes */
    private static function createState(string $value, array $attributes = [], string $entityId = self::ENTITY): EntityState
    {
        return new EntityState(new EntityId($entityId), $value, $attributes);
    }
}
