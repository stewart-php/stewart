<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Worker;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\Exception\HistoryError;
use Stewart\Contracts\Exception\HistoryException;
use Stewart\Contracts\Exception\ServiceCallError;
use Stewart\Contracts\Exception\ServiceCallException;
use Stewart\Contracts\History\Collection\HistoricalStateCollection;
use Stewart\Contracts\Service\ServiceResponse;
use Stewart\Runtime\Ipc\Message\RequestFailed;
use Stewart\Runtime\Model\ExceptionDetails;
use Stewart\Runtime\Model\WorkerId;
use Stewart\Runtime\Worker\CorrelationIdSequence;
use Stewart\Runtime\Worker\PendingRequest;
use Stewart\Runtime\Worker\PendingRequests;
use Stewart\Runtime\Worker\Subject\HistoryQuerySubject;
use Stewart\Runtime\Worker\Subject\ServiceCallSubject;
use Stewart\Testing\Exception\AssertsReason;
use Stewart\Testing\Time\EventLoopTicks;
use Throwable;

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

        $failure = HistoryException::createForReason(HistoryError::RecorderUnavailable, entityId: 'light.hall');

        $this->pending->rejectFromWire(RequestFailed::fromException($query->correlationId, $failure));

        $restored = $this->catchFailureOf($query);
        self::assertInstanceOf(HistoryException::class, $restored);
        self::assertSame(HistoryError::RecorderUnavailable, $restored->reason);
        self::assertSame($failure->getMessage(), $restored->getMessage());
        self::assertSame($failure->context, $restored->context);
    }

    public function testUnknownFailureReasonDegradesToUnreachable(): void
    {
        $call = $this->pending->open(new ServiceCallSubject('light', 'turn_on'));

        $this->pending->rejectFromWire(new RequestFailed($call->correlationId, 'something odd', new ExceptionDetails(ServiceCallException::class, 'melted', null)));

        $restored = $this->catchFailureOf($call);
        self::assertInstanceOf(ServiceCallException::class, $restored);
        self::assertSame(ServiceCallError::Unreachable, $restored->reason);
        self::assertSame('something odd', $restored->getMessage());
    }

    public function testAnswerOfWrongTypeFailsAsUnreachable(): void
    {
        $call = $this->pending->open(new ServiceCallSubject('light', 'turn_on'));

        $this->pending->resolve($call->correlationId, HistoricalStateCollection::fromList([]));

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

    /**
     * @template TResult of object
     *
     * @param PendingRequest<TResult> $request
     */
    private function catchFailureOf(PendingRequest $request): Throwable
    {
        try {
            $request->getFuture()->await();
        } catch (Throwable $e) {
            return $e;
        }

        self::fail('The request did not fail.');
    }
}
