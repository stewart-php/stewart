<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Worker\Context;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Event\EventPayload;
use Stewart\Contracts\Exception\EventFireError;
use Stewart\Contracts\Exception\EventFireException;
use Stewart\Contracts\State\EventContext;
use Stewart\Contracts\Time\Duration;
use Stewart\Runtime\Exception\TransportException;
use Stewart\Runtime\Ipc\Message\EventFireFailed;
use Stewart\Runtime\Ipc\Message\EventFireRequest;
use Stewart\Runtime\Ipc\Transport;
use Stewart\Runtime\Model\ResourceScope;
use Stewart\Runtime\Model\WorkerId;
use Stewart\Runtime\Tests\Fixtures\Ipc\FailingTransport;
use Stewart\Runtime\Tests\Fixtures\Ipc\NullTransport;
use Stewart\Runtime\Worker\ConnectionStatus;
use Stewart\Runtime\Worker\Context\EventFirer;
use Stewart\Runtime\Worker\CorrelationIdSequence;
use Stewart\Runtime\Worker\PendingRequests;
use Stewart\Runtime\Worker\Subject\EventFireSubject;
use Stewart\Testing\Exception\AssertsReason;
use Stewart\Testing\Time\EventLoopTicks;
use Stewart\Testing\Time\ManualTimers;
use Throwable;

use function Amp\async;

#[CoversClass(EventFirer::class)]
#[CoversClass(EventFireSubject::class)]
final class EventFirerTest extends TestCase
{
    use AssertsReason;

    private ManualTimers $timers;

    private PendingRequests $pending;

    private ConnectionStatus $connection;

    protected function setUp(): void
    {
        $this->timers = new ManualTimers();
        $this->pending = new PendingRequests(new CorrelationIdSequence(new WorkerId(0)));
        $this->connection = new ConnectionStatus();
    }

    public function testRequestCarriesEventAndAnswerIsContext(): void
    {
        $transport = new NullTransport();
        $firer = $this->createFirer($transport);

        $fire = async(static fn() => $firer->fireEvent(ResourceScope::forApp(new AppId('demo')), new EventPayload('doorbell_pressed', ['button' => 'front'])));
        EventLoopTicks::settle();

        $request = $transport->sent[0] ?? null;
        self::assertInstanceOf(EventFireRequest::class, $request);
        self::assertSame('doorbell_pressed', $request->eventType);
        self::assertSame(['button' => 'front'], $request->data);
        self::assertSame('demo', $request->scope->wireValue());

        $this->pending->resolve($request->correlationId, new EventContext('fire-1'));

        $context = $fire->await();
        self::assertInstanceOf(EventContext::class, $context);
        self::assertSame('fire-1', $context->id);
    }

    public function testBrokerFailureReachesCaller(): void
    {
        $transport = new NullTransport();
        $firer = $this->createFirer($transport);

        $fire = async(static fn() => $firer->fireEvent(ResourceScope::shared(), new EventPayload('doorbell_pressed')));
        EventLoopTicks::settle();
        $request = $transport->sent[0] ?? null;
        self::assertInstanceOf(EventFireRequest::class, $request);

        $failed = EventFireFailed::fromException($request->correlationId, EventFireException::rejected('doorbell_pressed', 'Unauthorized', 'unauthorized'));
        $this->pending->reject($request->correlationId, $failed->toException());

        $this->assertThrowsReason(EventFireError::Rejected, static fn() => $fire->await());
    }

    public function testDisconnectedFailsWithoutSending(): void
    {
        $transport = new NullTransport();
        $this->connection->markLost();

        $this->assertThrowsReason(EventFireError::Unreachable, fn() => $this->fireFrom($transport));
        self::assertSame([], $transport->sent);
    }

    #[DataProvider('provideSendFailures')]
    public function testSendFailureIsMappedToItsReason(Throwable $failure, EventFireError $reason): void
    {
        $this->assertThrowsReason($reason, fn() => $this->fireFrom(new FailingTransport($failure)));
    }

    /** @return iterable<string, array{Throwable, EventFireError}> */
    public static function provideSendFailures(): iterable
    {
        yield 'a request that cannot be encoded' => [TransportException::unencodable(EventFireRequest::class, new RuntimeException('NAN')), EventFireError::Rejected];
        yield 'a channel that is gone' => [TransportException::sendFailed(new RuntimeException('broken pipe')), EventFireError::Unreachable];
    }

    public function testSilentBrokerTimesOut(): void
    {
        async(fn() => $this->timers->delay(Duration::seconds(1)))->ignore();

        $this->assertThrowsReason(EventFireError::TimedOut, fn() => $this->fireFrom(new NullTransport()));
        self::assertSame(0, $this->pending->failAll('nothing left'));
    }

    private function fireFrom(Transport $transport): void
    {
        $this->createFirer($transport)->fireEvent(ResourceScope::shared(), new EventPayload('doorbell_pressed'));
    }

    private function createFirer(Transport $transport): EventFirer
    {
        return new EventFirer($transport, $this->pending, $this->connection, $this->timers, Duration::seconds(1));
    }
}
