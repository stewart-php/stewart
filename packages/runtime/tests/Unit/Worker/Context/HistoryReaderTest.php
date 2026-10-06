<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Worker\Context;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\Exception\HistoryError;
use Stewart\Contracts\History\Collection\HistoricalStateCollection;
use Stewart\Contracts\History\EntityStateHistory;
use Stewart\Contracts\History\HistoryDetail;
use Stewart\Contracts\History\HistoryQuery;
use Stewart\Contracts\Time\Duration;
use Stewart\Runtime\Exception\TransportException;
use Stewart\Runtime\Ipc\Message\HistoryRequest;
use Stewart\Runtime\Ipc\Transport;
use Stewart\Runtime\Model\ResourceScope;
use Stewart\Runtime\Model\WorkerId;
use Stewart\Runtime\Tests\Fixtures\Ipc\FailingTransport;
use Stewart\Runtime\Tests\Fixtures\Ipc\NullTransport;
use Stewart\Runtime\Worker\ConnectionStatus;
use Stewart\Runtime\Worker\Context\HistoryReader;
use Stewart\Runtime\Worker\CorrelationIdSequence;
use Stewart\Runtime\Worker\PendingRequests;
use Stewart\Testing\Exception\AssertsReason;
use Stewart\Testing\Time\EventLoopTicks;
use Stewart\Testing\Time\ManualTimers;
use Throwable;

use function Amp\async;

#[CoversClass(HistoryReader::class)]
final class HistoryReaderTest extends TestCase
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

    public function testLookbackIsResolvedAgainstWorkerClock(): void
    {
        $transport = new NullTransport();
        $reader = $this->createReader($transport);

        $fetch = async(static fn() => $reader->fetchHistory(ResourceScope::forApp(new AppId('demo')), new EntityId('light.hall'), HistoryQuery::lastFor(Duration::minutes(5))->withAttributes()));
        EventLoopTicks::settle();

        $request = $transport->sent[0] ?? null;
        self::assertInstanceOf(HistoryRequest::class, $request);
        self::assertTrue($request->window->endsAt->equals($this->timers->clock->getNow()));
        self::assertTrue($request->window->getDuration()->equals(Duration::minutes(5)));
        self::assertSame(HistoryDetail::StateChangesWithAttributes, $request->detail);

        $this->pending->resolve($request->correlationId, HistoricalStateCollection::fromStates([]));

        $history = $fetch->await();
        self::assertInstanceOf(EntityStateHistory::class, $history);
        self::assertTrue($history->isEmpty());
        self::assertSame('light.hall', $history->entityId->value);
        self::assertSame($request->window, $history->window);
    }

    public function testDisconnectedFailsWithoutSending(): void
    {
        $transport = new NullTransport();
        $this->connection->markLost();

        $this->assertThrowsReason(HistoryError::Unreachable, fn() => $this->fetchFrom($transport));
        self::assertSame([], $transport->sent);
    }

    #[DataProvider('provideSendFailures')]
    public function testSendFailureIsMappedToItsReason(Throwable $failure, HistoryError $reason): void
    {
        $this->assertThrowsReason($reason, fn() => $this->fetchFrom(new FailingTransport($failure)));
    }

    /** @return iterable<string, array{Throwable, HistoryError}> */
    public static function provideSendFailures(): iterable
    {
        yield 'a request that cannot be encoded' => [TransportException::unencodable(HistoryRequest::class, new RuntimeException('NAN')), HistoryError::Rejected];
        yield 'a channel that is gone' => [TransportException::sendFailed(new RuntimeException('broken pipe')), HistoryError::Unreachable];
    }

    public function testSilentBrokerTimesOut(): void
    {
        async(fn() => $this->timers->delay(Duration::seconds(1)))->ignore();

        $this->assertThrowsReason(HistoryError::TimedOut, fn() => $this->fetchFrom(new NullTransport()));
        self::assertSame(0, $this->pending->failAll('nothing left'));
    }

    private function fetchFrom(Transport $transport): void
    {
        $this->createReader($transport)->fetchHistory(ResourceScope::shared(), new EntityId('light.hall'), HistoryQuery::lastFor(Duration::minutes(5)));
    }

    private function createReader(Transport $transport): HistoryReader
    {
        return new HistoryReader($transport, $this->pending, $this->connection, $this->timers, $this->timers->clock, Duration::seconds(1));
    }
}
