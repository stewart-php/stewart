<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Worker;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\Exception\EventFireError;
use Stewart\Contracts\Exception\ServiceCallError;
use Stewart\Contracts\Exception\ServiceCallException;
use Stewart\Contracts\Exception\StateError;
use Stewart\Contracts\Exception\TopicError;
use Stewart\Contracts\Exception\TopicException;
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
use Stewart\Contracts\Selector\Collection\SelectorCollection;
use Stewart\Contracts\State\Collection\EntityStateCollection;
use Stewart\Contracts\State\EntityState;
use Stewart\Contracts\State\EventContext;
use Stewart\Runtime\Exception\TransportException;
use Stewart\Runtime\Ipc\Message\EventFireRequest;
use Stewart\Runtime\Ipc\Message\ServiceCallRequest;
use Stewart\Runtime\Ipc\Transport;
use Stewart\Runtime\Model\ResourceScope;
use Stewart\Runtime\Model\WorkerId;
use Stewart\Runtime\Registry\RegistryCache;
use Stewart\Runtime\State\StateCache;
use Stewart\Runtime\Tests\Fixtures\Ipc\FailingTransport;
use Stewart\Runtime\Tests\Fixtures\Ipc\NullTransport;
use Stewart\Runtime\Tests\Fixtures\Worker\WorkerHaContextFixture;
use Stewart\Runtime\Worker\ConnectionStatus;
use Stewart\Runtime\Worker\CorrelationIdSequence;
use Stewart\Runtime\Worker\PendingRequests;
use Stewart\Runtime\Worker\WorkerHaContext;
use Stewart\Testing\Exception\AssertsReason;
use Stewart\Testing\Time\EventLoopTicks;
use Throwable;

use function Amp\async;

#[CoversClass(WorkerHaContext::class)]
final class WorkerHaContextTest extends TestCase
{
    use AssertsReason;

    /** @return iterable<string, array{Throwable, ServiceCallError}> */
    public static function provideSendFailures(): iterable
    {
        yield 'a request that cannot be encoded' => [TransportException::unencodable(ServiceCallRequest::class, new RuntimeException('NAN')), ServiceCallError::Rejected];
        yield 'a channel that is gone' => [TransportException::sendFailed(new RuntimeException('broken pipe')), ServiceCallError::Unreachable];
        yield 'anything else' => [new RuntimeException('surprise'), ServiceCallError::Unreachable];
    }

    #[DataProvider('provideSendFailures')]
    public function testSendFailureIsMappedToItsReason(Throwable $failure, ServiceCallError $reason): void
    {
        $transport = new FailingTransport($failure);

        try {
            self::createContext($transport)->callService('light', 'turn_on');
            self::fail('The request never left the worker.');
        } catch (ServiceCallException $e) {
            self::assertSame($reason, $e->reason);
            self::assertSame($failure, $e->getPrevious());
        }

        self::assertSame(1, $transport->attempts);
    }

    public function testEntityFilterListsStatesByRegistry(): void
    {
        $states = new StateCache();
        $states->replaceAll(EntityStateCollection::keyedByEntityId([new EntityState(new EntityId('light.kitchen'), 'on'), new EntityState(new EntityId('light.porch'), 'on')]));
        $registry = new RegistryCache();
        $registry->replaceIfNewer(IndexedRegistry::fromParts(
            AreaCollection::keyedByAreaId([new Area(new AreaId('kitchen'), 'Kitchen')]),
            FloorCollection::empty(),
            LabelCollection::empty(),
            DeviceCollection::empty(),
            RegisteredEntityCollection::keyedByEntityId([new RegisteredEntity(new EntityId('light.kitchen'), areaId: new AreaId('kitchen'))]),
        ), 1);
        $ha = WorkerHaContextFixture::createWorkerHaContext(new NullTransport(), ResourceScope::forApp(new AppId('demo')), stateCache: $states, registry: $registry);

        self::assertSame(['light.kitchen'], $ha->listStates(EntityFilter::inArea('kitchen'))->listEntityIds()->toStrings());
        self::assertSame(['light.kitchen', 'light.porch'], $ha->listStates(EntityFilter::inDomain('light'))->listEntityIds()->toStrings());
        self::assertSame('Kitchen', $ha->getRegistry()->findAreaByName('kitchen')?->name);
        self::assertSame('Kitchen', $ha->forApp(new AppId('other'))->getRegistry()->findArea('kitchen')?->name);
    }

    public function testPublishOnBrokenChannelThrowsTopicError(): void
    {
        $failure = TransportException::sendFailed(new RuntimeException('broken pipe'));

        try {
            self::createContext(new FailingTransport($failure))->publish('hall.motion', ['on' => true]);
            self::fail('A failed publish must reach the app.');
        } catch (TopicException $e) {
            self::assertSame(TopicError::PublishFailed, $e->reason);
            self::assertSame($failure, $e->getPrevious());
        }
    }

    public function testCallDuringOutageFailsLocally(): void
    {
        $transport = new NullTransport();
        $connection = new ConnectionStatus();
        $connection->markLost();

        try {
            self::createContext($transport, $connection)->callService('light', 'turn_on');
            self::fail('Home Assistant is disconnected.');
        } catch (ServiceCallException $e) {
            self::assertSame(ServiceCallError::Unreachable, $e->reason);
            self::assertStringContainsString('Home Assistant is disconnected', $e->getMessage());
        }

        self::assertSame([], $transport->sent);
    }

    public function testFiredEventIsScopedToTheApp(): void
    {
        $transport = new NullTransport();
        $pending = new PendingRequests(new CorrelationIdSequence(new WorkerId(0)));
        $context = WorkerHaContextFixture::createWorkerHaContext(transport: $transport, scope: ResourceScope::forApp(new AppId('demo')), pending: $pending);

        $fire = async(static fn() => $context->fireEvent('doorbell_pressed', ['button' => 'front']));
        EventLoopTicks::settle();

        $request = $transport->sent[0] ?? null;
        self::assertInstanceOf(EventFireRequest::class, $request);
        self::assertSame('demo', $request->scope->wireValue());
        self::assertSame(['button' => 'front'], $request->data);

        $pending->resolve($request->correlationId, new EventContext('fire-1'));
        $fired = $fire->await();
        self::assertInstanceOf(EventContext::class, $fired);
        self::assertSame('fire-1', $fired->id);
    }

    public function testInvalidEventDataFailsBeforeSending(): void
    {
        $transport = new NullTransport();

        /** @phpstan-ignore argument.type (a list is the invalid input under test) */
        $this->assertThrowsReason(EventFireError::DataNotKeyed, static fn() => self::createContext($transport)->fireEvent('doorbell_pressed', ['front']));
        self::assertSame([], $transport->sent);
    }

    #[DataProvider('provideSelectorsNamingStateChanged')]
    public function testStateChangedIsRejectedFromEvents(string|SelectorCollection $eventType): void
    {
        $this->assertThrowsReason(StateError::StateChangedViaEvents, fn() => self::createContext(new NullTransport())->watchEvents($eventType));
    }

    /** @return iterable<string, array{string|SelectorCollection}> */
    public static function provideSelectorsNamingStateChanged(): iterable
    {
        yield 'on its own' => ['state_changed'];
        yield 'among others' => [SelectorCollection::fromSpecs('zha_event', 'state_changed')];
    }

    public function testCoveringEventPatternIsAccepted(): void
    {
        self::createContext(new NullTransport())->watchEvents('state_*');

        $this->addToAssertionCount(1);
    }

    private static function createContext(Transport $transport, ConnectionStatus $connection = new ConnectionStatus()): WorkerHaContext
    {
        return WorkerHaContextFixture::createWorkerHaContext(
            transport: $transport,
            scope: ResourceScope::forApp(new AppId('demo')),
            connection: $connection,
        );
    }
}
