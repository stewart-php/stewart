<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Ipc;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use stdClass;
use Stewart\Runtime\Broker\Message\LogRecordHandler;
use Stewart\Runtime\Exception\ContainerError;
use Stewart\Runtime\Ipc\Message\LogRecord;
use Stewart\Runtime\Ipc\MessageHandlerIndex;
use Stewart\Runtime\Model\LogLevel;
use Stewart\Runtime\Model\ResourceScope;
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

    public function testRefusesTwoHandlersForOneMessage(): void
    {
        $this->assertThrowsReason(ContainerError::MessageHandlerDuplicated, fn() => MessageHandlerIndex::fromHandlers([new LogRecordHandler(new NullLogger()), new LogRecordHandler(new NullLogger())]));
    }
}
