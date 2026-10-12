<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Ipc;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use stdClass;
use Stewart\Contracts\Service\ServiceResponse;
use Stewart\Runtime\Broker\Message\LogRecordHandler;
use Stewart\Runtime\Exception\ContainerError;
use Stewart\Runtime\Ipc\Message\BrokerMessage;
use Stewart\Runtime\Ipc\Message\LogRecord;
use Stewart\Runtime\Ipc\Message\ServiceCallResult;
use Stewart\Runtime\Ipc\MessageHandlerIndex;
use Stewart\Runtime\Model\CorrelationId;
use Stewart\Runtime\Model\LogLevel;
use Stewart\Runtime\Model\ResourceScope;
use Stewart\Runtime\Model\WorkerId;
use Stewart\Runtime\Worker\CorrelationIdSequence;
use Stewart\Runtime\Worker\Message\BrokerMessageHandler;
use Stewart\Runtime\Worker\Message\CorrelatedReplyHandler;
use Stewart\Runtime\Worker\PendingRequests;
use Stewart\Testing\Exception\AssertsReason;

#[CoversClass(MessageHandlerIndex::class)]
final class MessageHandlerIndexTest extends TestCase
{
    use AssertsReason;

    public function testFindsTheHandlerForTheMessageClass(): void
    {
        $handler = new LogRecordHandler(new NullLogger());

        $found = MessageHandlerIndex::fromHandlers([$handler])
            ->findHandlerFor(new LogRecord(ResourceScope::shared(), LogLevel::Info, 'hello', [], null));

        self::assertSame($handler, $found);
    }

    public function testFindsNothingForAnUnhandledClass(): void
    {
        self::assertNull(MessageHandlerIndex::fromHandlers([])->findHandlerFor(new stdClass()));
    }

    public function testFallsBackToAnImplementedInterface(): void
    {
        $handler = new CorrelatedReplyHandler(new PendingRequests(new CorrelationIdSequence(new WorkerId(0))));

        self::assertSame($handler, MessageHandlerIndex::fromHandlers([$handler])->findHandlerFor(self::createServiceCallResult()));
    }

    public function testExactClassWinsOverInterface(): void
    {
        $exact = new class implements BrokerMessageHandler {
            public function handledMessageClass(): string
            {
                return ServiceCallResult::class;
            }

            public function handle(BrokerMessage $message): void {}
        };
        $index = MessageHandlerIndex::fromHandlers([new CorrelatedReplyHandler(new PendingRequests(new CorrelationIdSequence(new WorkerId(0)))), $exact]);

        self::assertSame($exact, $index->findHandlerFor(self::createServiceCallResult()));
    }

    public function testRefusesTwoHandlersForOneMessage(): void
    {
        $this->assertThrowsReason(ContainerError::MessageHandlerDuplicated, fn() => MessageHandlerIndex::fromHandlers([new LogRecordHandler(new NullLogger()), new LogRecordHandler(new NullLogger())]));
    }

    private static function createServiceCallResult(): ServiceCallResult
    {
        return new ServiceCallResult(new CorrelationId('w0:1'), new ServiceResponse('light', 'turn_on'));
    }
}
