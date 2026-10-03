<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Worker;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Exception\ServiceCallError;
use Stewart\Contracts\Exception\ServiceCallException;
use Stewart\Contracts\Exception\StateError;
use Stewart\Contracts\Exception\TopicError;
use Stewart\Contracts\Exception\TopicException;
use Stewart\Contracts\Selector\Collection\SelectorCollection;
use Stewart\Runtime\Exception\TransportException;
use Stewart\Runtime\Ipc\Message\ServiceCallRequest;
use Stewart\Runtime\Ipc\Transport;
use Stewart\Runtime\Model\ResourceScope;
use Stewart\Runtime\Tests\Fixtures\Ipc\FailingTransport;
use Stewart\Runtime\Tests\Fixtures\Ipc\NullTransport;
use Stewart\Runtime\Tests\Fixtures\Worker\WorkerHaContextFixture;
use Stewart\Runtime\Worker\ConnectionStatus;
use Stewart\Runtime\Worker\WorkerHaContext;
use Stewart\Testing\Exception\AssertsReason;
use Throwable;

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
