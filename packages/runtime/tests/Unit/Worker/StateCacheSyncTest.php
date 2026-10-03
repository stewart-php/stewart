<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Worker;

use Amp\DeferredCancellation;
use Amp\NullCancellation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\State\Collection\EntityStateCollection;
use Stewart\Contracts\State\Collection\StateChangeCollection;
use Stewart\Contracts\State\EntityState;
use Stewart\Contracts\State\StateChange;
use Stewart\Contracts\State\StateChangeOrigin;
use Stewart\Contracts\Time\Instant;
use Stewart\Runtime\State\StateCache;
use Stewart\Runtime\Worker\StateCacheSync;
use Stewart\Testing\Logging\RecordingLogger;

#[CoversClass(StateCacheSync::class)]
final class StateCacheSyncTest extends TestCase
{
    private StateCache $stateCache;

    private RecordingLogger $logger;

    private StateCacheSync $sync;

    protected function setUp(): void
    {
        $this->stateCache = new StateCache();
        $this->logger = new RecordingLogger();
        $this->sync = new StateCacheSync($this->stateCache, $this->logger);
    }

    public function testOnlyTheFirstSnapshotAnnouncesTheSeed(): void
    {
        $this->sync->seedFromSnapshot(self::createStates('off'), 1);
        $this->sync->seedFromSnapshot(self::createStates('on'), 2);

        self::assertTrue($this->sync->awaitFirstSeed(new NullCancellation()));
        self::assertSame(['State cache seeded'], $this->logger->listMessagesAt('debug'));
        self::assertSame('on', $this->stateCache->find(new EntityId('light.hall'))?->state);
    }

    public function testHaltedWaitReportsThatNothingWasSeeded(): void
    {
        $halt = new DeferredCancellation();
        $halt->cancel();

        self::assertFalse($this->sync->awaitFirstSeed($halt->getCancellation()));
    }

    public function testCacheIsOlderOnlyThanALaterRevision(): void
    {
        $this->sync->seedFromSnapshot(self::createStates('off'), 4);

        self::assertFalse($this->sync->isOlderThan(4));
        self::assertTrue($this->sync->isOlderThan(5));
    }

    public function testResyncReplacesCacheAndReturnsChanges(): void
    {
        $this->sync->seedFromSnapshot(self::createStates('off'), 1);

        $changes = $this->sync->replaceWithResyncedStates(self::createStates('on'), 2, Instant::fromEpochMicroseconds(0));

        self::assertCount(1, $changes);
        self::assertFalse($this->sync->isOlderThan(2));
        self::assertSame(1, $this->sync->countEntities());
        self::assertSame('on', $this->stateCache->find(new EntityId('light.hall'))?->state);
    }

    public function testOutageEdgeBecomesResyncChange(): void
    {
        $changes = $this->computeResyncChanges([self::createState('light.hall', 'off', 1)], [self::createState('light.hall', 'on', 2)]);

        self::assertCount(1, $changes);
        $change = $changes->getFirst();
        self::assertNotNull($change);
        self::assertTrue($change->changedTo('on'));
        self::assertSame(StateChangeOrigin::Resync, $change->origin);
        self::assertTrue($change->isReconstructed());
        self::assertEquals(self::createInstantAt(2), $change->firedAt);
        self::assertNull($change->context);
    }

    public function testAttributeOnlyWriteCounts(): void
    {
        $changes = $this->computeResyncChanges(
            [new EntityState(new EntityId('sensor.t'), '21', ['battery' => 90], self::createInstantAt(1), self::createInstantAt(1))],
            [new EntityState(new EntityId('sensor.t'), '21', ['battery' => 89], self::createInstantAt(1), self::createInstantAt(5))],
        );

        self::assertSame(['sensor.t:21>21'], self::describeEdges($changes));
    }

    public function testWithoutTimestampsStateAndAttributesDecide(): void
    {
        $changes = $this->computeResyncChanges(
            [new EntityState(new EntityId('a.same'), 'on', ['x' => 1]), new EntityState(new EntityId('a.moved'), 'on')],
            [new EntityState(new EntityId('a.same'), 'on', ['x' => 1]), new EntityState(new EntityId('a.moved'), 'off')],
        );

        self::assertSame(['a.moved:on>off'], self::describeEdges($changes));
        self::assertEquals(self::createInstantAt(9), $changes->getFirst()?->firedAt);
    }

    public function testUntouchedEntityProducesNothing(): void
    {
        self::assertTrue($this->computeResyncChanges([self::createState('light.hall', 'on', 1)], [self::createState('light.hall', 'on', 1)])->isEmpty());
    }

    public function testAddedInOrderRemovedLast(): void
    {
        $changes = $this->computeResyncChanges(
            [self::createState('light.gone', 'on', 1), self::createState('light.kept', 'on', 1)],
            [self::createState('light.new', 'on', 3), self::createState('light.kept', 'on', 1), self::createState('light.newer', 'off', 4)],
        );

        self::assertSame(['light.new:>on', 'light.newer:>off', 'light.gone:on>'], self::describeEdges($changes));
        $removed = $changes->listValues()[2];
        self::assertTrue($removed->isRemoved());
        self::assertEquals(self::createInstantAt(9), $removed->firedAt);
    }

    /**
     * @param list<EntityState> $before
     * @param list<EntityState> $after
     */
    private function computeResyncChanges(array $before, array $after): StateChangeCollection
    {
        $this->sync->seedFromSnapshot(EntityStateCollection::keyedByEntityId($before), 1);

        return $this->sync->replaceWithResyncedStates(EntityStateCollection::keyedByEntityId($after), 2, self::createInstantAt(9));
    }

    /** @return list<string> */
    private static function describeEdges(StateChangeCollection $changes): array
    {
        return $changes->mapToList(
            static fn(StateChange $c): string => \sprintf('%s:%s>%s', $c->entityId->value, $c->from?->state, $c->to?->state),
        );
    }

    private static function createState(string $entityId, string $state, int $updatedSecond): EntityState
    {
        return new EntityState(new EntityId($entityId), $state, [], self::createInstantAt($updatedSecond), self::createInstantAt($updatedSecond));
    }

    private static function createInstantAt(int $second): Instant
    {
        return Instant::fromEpochMicroseconds($second * 1_000_000);
    }

    private static function createStates(string $hall): EntityStateCollection
    {
        return EntityStateCollection::keyedByEntityId([new EntityState(new EntityId('light.hall'), $hall)]);
    }
}
