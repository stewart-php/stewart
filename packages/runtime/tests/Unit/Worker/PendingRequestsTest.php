<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Worker;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\Exception\HistoryError;
use Stewart\Contracts\Exception\HistoryException;
use Stewart\Contracts\Exception\ServiceCallError;
use Stewart\Contracts\History\Collection\HistoricalStateCollection;
use Stewart\Contracts\Service\ServiceResponse;
use Stewart\Runtime\Model\WorkerId;
use Stewart\Runtime\Worker\CorrelationIdSequence;
use Stewart\Runtime\Worker\PendingRequest;
use Stewart\Runtime\Worker\PendingRequests;
use Stewart\Runtime\Worker\Subject\HistoryQuerySubject;
use Stewart\Runtime\Worker\Subject\ServiceCallSubject;
use Stewart\Testing\Exception\AssertsReason;
use Stewart\Testing\Time\EventLoopTicks;

use function Amp\async;

#[CoversClass(PendingRequests::class)]
#[CoversClass(PendingRequest::class)]
#[CoversClass(ServiceCallSubject::class)]
#[CoversClass(HistoryQuerySubject::class)]
final class PendingRequestsTest extends TestCase
{
    use AssertsReason;

    private PendingRequests $pending;

    protected function setUp(): void
    {
        $this->pending = new PendingRequests(new CorrelationIdSequence(new WorkerId(0)));
    }

    public function testAnswerReachesWaiter(): void
    {
        $call = $this->pending->open(new ServiceCallSubject('light', 'turn_on'));
        $response = new ServiceResponse('light', 'turn_on');

        $this->pending->resolve($call->correlationId, $response);

        self::assertSame($response, $call->getFuture()->await());
    }

    public function testBrokerFailureReachesWaiter(): void
    {
        $query = $this->pending->open(new HistoryQuerySubject(new EntityId('light.hall')));

        $this->pending->reject($query->correlationId, HistoryException::recorderUnavailable(new EntityId('light.hall')));

        $this->assertThrowsReason(HistoryError::RecorderUnavailable, static fn() => $query->getFuture()->await());
    }

    public function testAnswerOfWrongTypeFailsAsUnreachable(): void
    {
        $call = $this->pending->open(new ServiceCallSubject('light', 'turn_on'));

        $this->pending->resolve($call->correlationId, HistoricalStateCollection::fromStates([]));

        $this->assertThrowsReason(ServiceCallError::Unreachable, static fn() => $call->getFuture()->await());
    }

    public function testFailAllFailsEveryLiveWaiterBySubject(): void
    {
        $call = $this->pending->open(new ServiceCallSubject('light', 'turn_on'));
        $query = $this->pending->open(new HistoryQuerySubject(new EntityId('light.hall')));
        $callWaiter = async(static fn() => $call->getFuture()->await());
        $queryWaiter = async(static fn() => $query->getFuture()->await());
        EventLoopTicks::settle();

        self::assertSame(2, $this->pending->failAll('the broker is gone'));

        $this->assertThrowsReason(ServiceCallError::Unreachable, static fn() => $callWaiter->await());
        $this->assertThrowsReason(HistoryError::Unreachable, static fn() => $queryWaiter->await());
    }

    public function testAnswerAfterFailAllIsIgnored(): void
    {
        $call = $this->pending->open(new ServiceCallSubject('light', 'turn_on'));
        $this->pending->failAll('the broker is gone');

        $this->pending->resolve($call->correlationId, new ServiceResponse('light', 'turn_on'));

        self::assertSame(0, $this->pending->failAll('again'));
        $this->assertThrowsReason(ServiceCallError::Unreachable, static fn() => $call->getFuture()->await());
    }
}
