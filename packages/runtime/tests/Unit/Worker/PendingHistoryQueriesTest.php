<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Worker;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\Exception\HistoryError;
use Stewart\Contracts\Exception\HistoryException;
use Stewart\Contracts\History\Collection\HistoricalStateCollection;
use Stewart\Contracts\History\HistoryWindow;
use Stewart\Contracts\State\EntityState;
use Stewart\Contracts\Time\Instant;
use Stewart\Runtime\Ipc\Message\HistoryFailed;
use Stewart\Runtime\Model\WorkerId;
use Stewart\Runtime\Worker\CorrelationIdSequence;
use Stewart\Runtime\Worker\PendingHistoryQueries;
use Stewart\Runtime\Worker\PendingHistoryQuery;
use Stewart\Testing\Exception\AssertsReason;

#[CoversClass(PendingHistoryQueries::class)]
#[CoversClass(PendingHistoryQuery::class)]
final class PendingHistoryQueriesTest extends TestCase
{
    use AssertsReason;

    private PendingHistoryQueries $pending;

    protected function setUp(): void
    {
        $this->pending = new PendingHistoryQueries(new CorrelationIdSequence(new WorkerId(0)));
    }

    public function testAnswerCarriesEntityAndWindowOfQuery(): void
    {
        $window = self::createWindow();
        $query = $this->pending->open(new EntityId('light.hall'), $window);

        $this->pending->resolve($query->correlationId, HistoricalStateCollection::fromStates([new EntityState(new EntityId('light.hall'), 'on')]));

        $history = $query->getFuture()->await();
        self::assertSame('light.hall', $history->entityId->value);
        self::assertSame($window, $history->window);
        self::assertSame('on', $history->getStateAtStart()?->state);
    }

    public function testBrokerFailureReachesWaiter(): void
    {
        $query = $this->pending->open(new EntityId('light.hall'), self::createWindow());

        $this->pending->reject(HistoryFailed::fromException($query->correlationId, HistoryException::recorderUnavailable(new EntityId('light.hall'))));

        $this->assertThrowsReason(HistoryError::RecorderUnavailable, static fn() => $query->getFuture()->await());
    }

    public function testAnswerAfterFailAllIsIgnored(): void
    {
        $query = $this->pending->open(new EntityId('light.hall'), self::createWindow());

        self::assertSame(1, $this->pending->failAll('the broker is gone'));
        $this->pending->resolve($query->correlationId, HistoricalStateCollection::fromStates([]));

        self::assertSame(0, $this->pending->failAll('again'));
        $this->assertThrowsReason(HistoryError::Unreachable, static fn() => $query->getFuture()->await());
    }

    private static function createWindow(): HistoryWindow
    {
        return new HistoryWindow(Instant::fromEpochMicroseconds(0), Instant::fromEpochMicroseconds(60_000_000));
    }
}
