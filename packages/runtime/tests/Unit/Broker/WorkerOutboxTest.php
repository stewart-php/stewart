<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Broker;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\Event\HaEvent;
use Stewart\Contracts\Service\ServiceResponse;
use Stewart\Contracts\State\Collection\EntityStateCollection;
use Stewart\Contracts\State\EntityState;
use Stewart\Contracts\State\StateChange;
use Stewart\Contracts\Time\Instant;
use Stewart\Contracts\Topic\TopicEvent;
use Stewart\Runtime\Broker\OutboxLimits;
use Stewart\Runtime\Broker\WorkerOutbox;
use Stewart\Runtime\Ipc\Message\BrokerMessage;
use Stewart\Runtime\Ipc\Message\EventFired;
use Stewart\Runtime\Ipc\Message\Ping;
use Stewart\Runtime\Ipc\Message\ServiceCallResult;
use Stewart\Runtime\Ipc\Message\StateChangeBatch;
use Stewart\Runtime\Ipc\Message\StateSnapshot;
use Stewart\Runtime\Ipc\Message\TopicMessage;
use Stewart\Runtime\Ipc\Wire\EncodedStateChange;
use Stewart\Runtime\Ipc\Wire\EntityStatesFragment;
use Stewart\Runtime\Ipc\Wire\IpcCodec;
use Stewart\Runtime\Model\CorrelationId;
use Stewart\Runtime\Model\SubscriptionId;

#[CoversClass(WorkerOutbox::class)]
#[CoversClass(EncodedStateChange::class)]
final class WorkerOutboxTest extends TestCase
{
    public function testControlMessagesAreNeverDropped(): void
    {
        $outbox = self::createOutbox(eventBuffer: 1);

        for ($i = 0; $i < 50; ++$i) {
            $outbox->enqueue(new ServiceCallResult(new CorrelationId('0:' . $i), new ServiceResponse('light', 'turn_on')));
        }

        self::assertCount(50, self::drain($outbox));
    }

    public function testConsecutiveStateChangesTravelAsOneBatch(): void
    {
        $outbox = self::createOutbox();

        $outbox->pushStateChange(self::createEncodedChange('light.a', 'off', 'on'));
        $outbox->pushStateChange(self::createEncodedChange('light.b', 'off', 'on'));

        $sent = self::drain($outbox);

        self::assertCount(1, $sent);
        self::assertSame(['light.a:off>on', 'light.b:off>on'], self::describeEdges($sent[0]));
        self::assertSame(1, $outbox->buildStatus()->stateBatchesSent);
        self::assertSame(2, $outbox->buildStatus()->largestStateBatch);
    }

    public function testBurstMergesIntoFirstAndLatest(): void
    {
        $outbox = self::createOutbox();

        $outbox->pushStateChange(self::createEncodedChange('light.a', 'on', 'off'));
        $outbox->pushStateChange(self::createEncodedChange('light.a', 'off', 'on'));
        $outbox->pushStateChange(self::createEncodedChange('light.a', 'on', 'dim'));

        self::assertSame(['light.a:on>off', 'light.a:off>dim'], self::describeEdges(self::drain($outbox)[0]));
        self::assertSame(1, $outbox->coalescedStateChanges);
    }

    public function testFlapKeepsBothEdges(): void
    {
        $outbox = self::createOutbox();

        $outbox->pushStateChange(self::createEncodedChange('light.a', 'on', 'off'));
        $outbox->pushStateChange(self::createEncodedChange('light.a', 'off', 'on'));

        $changes = self::decodeChanges(self::drain($outbox)[0]);

        self::assertTrue($changes[0]->changedTo('off'));
        self::assertTrue($changes[1]->changedTo('on'));
        self::assertSame(0, $outbox->coalescedStateChanges);
    }

    public function testMergedChangeMovesBehindEarlierEvent(): void
    {
        $outbox = self::createOutbox();

        $outbox->pushStateChange(self::createEncodedChange('light.a', '1', '2'));
        $outbox->pushStateChange(self::createEncodedChange('light.a', '2', '3'));
        $outbox->enqueue(new EventFired(new HaEvent('zha_event'), [new SubscriptionId('w0:2')]));
        $outbox->pushStateChange(self::createEncodedChange('light.a', '3', '4'));

        $sent = self::drain($outbox);

        self::assertCount(3, $sent);
        self::assertSame(['light.a:1>2'], self::describeEdges($sent[0]));
        self::assertInstanceOf(EventFired::class, $sent[1]);
        self::assertSame(['light.a:2>4'], self::describeEdges($sent[2]));
    }

    public function testEventEndsABatchSoOrderIsKept(): void
    {
        $outbox = self::createOutbox();

        $outbox->pushStateChange(self::createEncodedChange('light.a', '1', '2'));
        $outbox->enqueue(new EventFired(new HaEvent('zha_event'), [new SubscriptionId('w0:2')]));
        $outbox->pushStateChange(self::createEncodedChange('light.b', '1', '2'));

        $sent = self::drain($outbox);

        self::assertCount(3, $sent);
        self::assertSame(['light.a:1>2'], self::describeEdges($sent[0]));
        self::assertInstanceOf(EventFired::class, $sent[1]);
        self::assertSame(['light.b:1>2'], self::describeEdges($sent[2]));
    }

    public function testBatchNeverExceedsItsLimit(): void
    {
        $outbox = self::createOutbox(stateBatch: 2);

        foreach (['a', 'b', 'c', 'd', 'e'] as $name) {
            $outbox->pushStateChange(self::createEncodedChange('light.' . $name, '1', '2'));
        }

        $sizes = array_map(static fn(BrokerMessage $message): int => self::assertStateChangeBatch($message)->changes->collection->count(), self::drain($outbox));

        self::assertSame([2, 2, 1], $sizes);
        self::assertSame(3, $outbox->buildStatus()->stateBatchesSent);
        self::assertSame(2, $outbox->buildStatus()->largestStateBatch);
    }

    public function testSnapshotStopsMergingAcrossIt(): void
    {
        $outbox = self::createOutbox();

        $outbox->pushStateChange(self::createEncodedChange('light.a', '1', '2'));
        $outbox->pushStateChange(self::createEncodedChange('light.a', '2', '3'));
        $outbox->enqueue(new StateSnapshot(EntityStatesFragment::fromCollection(EntityStateCollection::empty()), 7));
        $outbox->pushStateChange(self::createEncodedChange('light.a', '3', '4'));
        $outbox->pushStateChange(self::createEncodedChange('light.a', '4', '5'));

        $sent = self::drain($outbox);

        self::assertCount(3, $sent);
        self::assertSame(['light.a:1>2', 'light.a:2>3'], self::describeEdges($sent[0]));
        self::assertInstanceOf(StateSnapshot::class, $sent[1]);
        self::assertSame(['light.a:3>4', 'light.a:4>5'], self::describeEdges($sent[2]));
        self::assertSame(0, $outbox->coalescedStateChanges);
    }

    public function testChangeAlreadySentIsNotMergedInto(): void
    {
        $outbox = self::createOutbox();

        $outbox->pushStateChange(self::createEncodedChange('light.a', '1', '2'));
        $outbox->pushStateChange(self::createEncodedChange('light.a', '2', '3'));
        $outbox->takeNext();
        $outbox->pushStateChange(self::createEncodedChange('light.a', '3', '4'));
        $outbox->pushStateChange(self::createEncodedChange('light.a', '4', '5'));

        self::assertSame(['light.a:3>4', 'light.a:4>5'], self::describeEdges(self::drain($outbox)[0]));
        self::assertSame(0, $outbox->coalescedStateChanges);
    }

    public function testOneEncodingIsSharedByEveryOutbox(): void
    {
        $change = self::createEncodedChange('light.a', '1', '2');
        $first = self::createOutbox();
        $second = self::createOutbox();

        $first->pushStateChange($change);
        $second->pushStateChange($change);

        $mapper = IpcCodec::createIpcWireMapper();

        self::assertSame(self::assertStateChangeBatch(self::drain($first)[0])->changes->encodeToJson($mapper), self::assertStateChangeBatch(self::drain($second)[0])->changes->encodeToJson($mapper));
        self::assertSame($change->encodeToJson($mapper), $change->encodeToJson(IpcCodec::createIpcWireMapper()));
    }

    public function testTopicMessagesBeyondTheLimitDropTheOldest(): void
    {
        $outbox = self::createOutbox(eventBuffer: 2);

        foreach (['first', 'second', 'third'] as $topic) {
            $outbox->enqueue(new TopicMessage(new TopicEvent($topic, null, new AppId('app'), Instant::fromEpochMicroseconds(0)), [new SubscriptionId('w0:1')]));
        }

        $topics = array_map(
            static fn(BrokerMessage $message): string => $message instanceof TopicMessage ? $message->event->topic : '',
            self::drain($outbox),
        );

        self::assertSame(['second', 'third'], $topics);
        self::assertSame(1, $outbox->dropped);
    }

    public function testEventsAndTopicMessagesShareOneLimit(): void
    {
        $outbox = self::createOutbox(eventBuffer: 2);

        $outbox->enqueue(new TopicMessage(new TopicEvent('oldest', null, new AppId('app'), Instant::fromEpochMicroseconds(0)), [new SubscriptionId('w0:1')]));
        $outbox->enqueue(new EventFired(new HaEvent('zha_event'), [new SubscriptionId('w0:2')]));
        $outbox->enqueue(new EventFired(new HaEvent('hue_event'), [new SubscriptionId('w0:2')]));

        $sent = self::drain($outbox);

        self::assertCount(2, $sent);
        self::assertInstanceOf(EventFired::class, $sent[0]);
        self::assertSame('zha_event', $sent[0]->event->type);
        self::assertInstanceOf(EventFired::class, $sent[1]);
        self::assertSame('hue_event', $sent[1]->event->type);
        self::assertSame(1, $outbox->dropped);
    }

    public function testProbesAReadingWorkerGetsArriveInTurn(): void
    {
        $outbox = self::createOutbox();

        $outbox->enqueue(new Ping(1, Instant::fromEpochMicroseconds(0)));
        $first = self::drain($outbox);
        $outbox->enqueue(new Ping(2, Instant::fromEpochMicroseconds(0)));

        self::assertSame([1, 2], array_map(static fn(BrokerMessage $m): int => $m instanceof Ping ? $m->nonce : 0, [...$first, ...self::drain($outbox)]));
    }

    public function testUnansweredProbeIsReplacedByTheNextOne(): void
    {
        $outbox = self::createOutbox();

        $outbox->enqueue(new Ping(1, Instant::fromEpochMicroseconds(0)));
        $outbox->enqueue(new ServiceCallResult(new CorrelationId('0:1'), new ServiceResponse('light', 'turn_on')));
        $outbox->enqueue(new Ping(2, Instant::fromEpochMicroseconds(0)));
        $outbox->enqueue(new Ping(3, Instant::fromEpochMicroseconds(0)));

        $sent = self::drain($outbox);

        self::assertCount(2, $sent, 'A wedged worker owes one answer, not one per interval.');
        self::assertInstanceOf(Ping::class, $sent[0]);
        self::assertSame(3, $sent[0]->nonce, 'The probe keeps its place in the queue and carries the newest nonce.');
    }

    public function testClearingKeepsTheDropCount(): void
    {
        $outbox = self::createOutbox(eventBuffer: 1);

        $outbox->enqueue(new EventFired(new HaEvent('a'), [new SubscriptionId('w0:1')]));
        $outbox->enqueue(new EventFired(new HaEvent('b'), [new SubscriptionId('w0:1')]));
        $outbox->clear();

        self::assertSame(1, $outbox->dropped);
        self::assertSame(0, $outbox->buildStatus()->queued);
    }

    public function testStatusCountsWhatIsQueuedAndWhatWasLost(): void
    {
        $outbox = self::createOutbox(eventBuffer: 1);

        $outbox->enqueue(new TopicMessage(new TopicEvent('a', null, new AppId('demo'), Instant::fromEpochMicroseconds(0)), [new SubscriptionId('w0:0')]));
        $outbox->enqueue(new TopicMessage(new TopicEvent('b', null, new AppId('demo'), Instant::fromEpochMicroseconds(0)), []));
        $outbox->pushStateChange(self::createEncodedChange('light.a', '1', '2'));
        $outbox->pushStateChange(self::createEncodedChange('light.a', '2', '3'));
        $outbox->pushStateChange(self::createEncodedChange('light.a', '3', '4'));

        $status = $outbox->buildStatus();

        self::assertSame(3, $status->queued);
        self::assertSame(1, $status->dropped);
        self::assertSame(1, $status->coalescedStateChanges);
    }

    private static function createOutbox(int $eventBuffer = 10, int $stateBatch = 256): WorkerOutbox
    {
        return new WorkerOutbox(new OutboxLimits($eventBuffer, $stateBatch));
    }

    private static function createEncodedChange(string $entityId, string $from, string $to): EncodedStateChange
    {
        return new EncodedStateChange(new StateChange(new EntityId($entityId), new EntityState(new EntityId($entityId), $from), new EntityState(new EntityId($entityId), $to)));
    }

    private static function assertStateChangeBatch(BrokerMessage $message): StateChangeBatch
    {
        self::assertInstanceOf(StateChangeBatch::class, $message);

        return $message;
    }

    /** @return list<string> */
    private static function describeEdges(BrokerMessage $message): array
    {
        return array_map(
            static fn(StateChange $change): string => \sprintf('%s:%s>%s', $change->entityId, $change->from?->state, $change->to?->state),
            self::decodeChanges($message),
        );
    }

    /** @return list<StateChange> */
    private static function decodeChanges(BrokerMessage $message): array
    {
        $codec = IpcCodec::createForWorkerBootstrap();
        $decoded = $codec->decodeMessage($codec->encodeMessage(self::assertStateChangeBatch($message)));
        self::assertInstanceOf(StateChangeBatch::class, $decoded);

        return $decoded->changes->collection->listValues();
    }

    /** @return list<BrokerMessage> */
    private static function drain(WorkerOutbox $outbox): array
    {
        $sent = [];

        while (($message = $outbox->takeNext()) !== null) {
            $sent[] = $message;
        }

        return $sent;
    }
}
