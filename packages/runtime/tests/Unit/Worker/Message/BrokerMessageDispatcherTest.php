<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Worker\Message;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Stewart\Contracts\Exception\ServiceCallException;
use Stewart\Contracts\Service\ServiceResponse;
use Stewart\Contracts\Time\Duration;
use Stewart\Runtime\Ipc\Message\ServiceCallResult;
use Stewart\Runtime\Ipc\Message\Shutdown;
use Stewart\Runtime\Ipc\Message\SubscriptionAck;
use Stewart\Runtime\Model\SubscriptionId;
use Stewart\Runtime\Model\WorkerId;
use Stewart\Runtime\Tests\Fixtures\Worker\RecordingDispatchListener;
use Stewart\Runtime\Worker\CorrelationIdSequence;
use Stewart\Runtime\Worker\Message\BrokerMessageDispatcher;
use Stewart\Runtime\Worker\Message\CorrelatedReplyHandler;
use Stewart\Runtime\Worker\Message\SubscriptionAckHandler;
use Stewart\Runtime\Worker\PendingRequests;
use Stewart\Testing\Logging\RecordingLogger;

#[CoversClass(BrokerMessageDispatcher::class)]
#[CoversClass(CorrelatedReplyHandler::class)]
#[CoversClass(SubscriptionAckHandler::class)]
final class BrokerMessageDispatcherTest extends TestCase
{
    public function testRefusedSubscriptionIsReported(): void
    {
        $logger = new RecordingLogger();

        new BrokerMessageDispatcher([new SubscriptionAckHandler($logger, RecordingDispatchListener::createDispatcher('w0', 10))], new NullLogger())
            ->dispatch(new SubscriptionAck(new SubscriptionId('w0:1'), false, 'worker-local'));

        self::assertSame(['Broker rejected a subscription'], $logger->listMessagesAt('error'));
        self::assertSame('worker-local', $logger->records->getFirst()?->context['reason']);
    }

    public function testAcceptedSubscriptionSaysNothing(): void
    {
        $logger = new RecordingLogger();

        new BrokerMessageDispatcher([new SubscriptionAckHandler($logger, RecordingDispatchListener::createDispatcher('w0', 10))], new NullLogger())
            ->dispatch(new SubscriptionAck(new SubscriptionId('w0:1'), true, null));

        self::assertTrue($logger->records->isEmpty());
    }

    public function testCorrelatedReplyResolvesItsPendingRequest(): void
    {
        $pending = new PendingRequests(new CorrelationIdSequence(new WorkerId(0)));
        $call = $pending->open(ServiceResponse::class, static fn(string $detail): ServiceCallException => ServiceCallException::unreachable('light', 'turn_on', $detail));
        $response = new ServiceResponse('light', 'turn_on');

        new BrokerMessageDispatcher([new CorrelatedReplyHandler($pending)], new NullLogger())
            ->dispatch(new ServiceCallResult($call->correlationId, $response));

        self::assertSame($response, $call->getFuture()->await());
    }

    public function testMessageNobodyHandlesIsLoggedAndDropped(): void
    {
        $logger = new RecordingLogger();

        new BrokerMessageDispatcher([], $logger)->dispatch(new Shutdown('bye', Duration::zero()));

        self::assertSame(['Ignoring an unknown broker message'], $logger->listMessagesAt('warning'));
    }
}
