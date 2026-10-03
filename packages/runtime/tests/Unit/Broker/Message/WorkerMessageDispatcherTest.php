<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Broker\Message;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Stewart\Contracts\App\AppId;
use Stewart\Runtime\App\AppDefinition;
use Stewart\Runtime\App\Collection\AppDefinitionCollection;
use Stewart\Runtime\Broker\Message\LogRecordHandler;
use Stewart\Runtime\Broker\Message\WorkerMessageDispatcher;
use Stewart\Runtime\Broker\OutboxLimits;
use Stewart\Runtime\Broker\WorkerHandle;
use Stewart\Runtime\Broker\WorkerSlot;
use Stewart\Runtime\Ipc\Message\LogRecord;
use Stewart\Runtime\Ipc\Message\Unsubscribe;
use Stewart\Runtime\Model\LogLevel;
use Stewart\Runtime\Model\ResourceScope;
use Stewart\Runtime\Model\SubscriptionId;
use Stewart\Runtime\Model\WorkerId;
use Stewart\Runtime\Tests\Fixtures\Apps\Demo;
use Stewart\Runtime\Tests\Fixtures\Broker\FakeWorkerProcess;
use Stewart\Testing\Logging\RecordingLogger;

#[CoversClass(WorkerMessageDispatcher::class)]
#[CoversClass(LogRecordHandler::class)]
final class WorkerMessageDispatcherTest extends TestCase
{
    public function testMessageReachesTheHandlerForItsClass(): void
    {
        $logger = new RecordingLogger();

        new WorkerMessageDispatcher([new LogRecordHandler($logger)], new NullLogger())
            ->dispatch(self::createHandle(), new LogRecord(ResourceScope::shared(), LogLevel::Info, 'hello', [], null));

        self::assertSame(['hello'], $logger->listMessagesAt('info'));
        self::assertSame(3, $logger->records->getFirst()?->context['worker']);
    }

    public function testMessageNobodyHandlesIsLoggedAndDropped(): void
    {
        $logger = new RecordingLogger();

        new WorkerMessageDispatcher([], $logger)->dispatch(self::createHandle(), new Unsubscribe(new SubscriptionId('w3:1')));

        self::assertSame(['Ignoring an unknown worker message'], $logger->listMessagesAt('warning'));
        self::assertSame(Unsubscribe::class, $logger->records->getFirst()?->context['message']);
    }

    private static function createHandle(): WorkerHandle
    {
        return new WorkerHandle(new WorkerId(3), new FakeWorkerProcess(), new WorkerSlot(new WorkerId(3), AppDefinitionCollection::keyedByAppId([new AppDefinition(new AppId('demo'), Demo::class)])), new NullLogger(), new OutboxLimits(10, 256));
    }
}
