<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Broker;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Time\Duration;
use Stewart\Contracts\Time\Instant;
use Stewart\Runtime\App\AppDefinition;
use Stewart\Runtime\App\Collection\AppDefinitionCollection;
use Stewart\Runtime\Broker\OutboxLimits;
use Stewart\Runtime\Broker\WorkerHandle;
use Stewart\Runtime\Broker\WorkerSlot;
use Stewart\Runtime\Exception\TransportException;
use Stewart\Runtime\Ipc\Message\Ping;
use Stewart\Runtime\Ipc\Message\Shutdown;
use Stewart\Runtime\Model\WorkerId;
use Stewart\Runtime\Tests\Fixtures\Apps\Demo;
use Stewart\Runtime\Tests\Fixtures\Broker\FakeWorkerProcess;
use Stewart\Testing\Logging\RecordingLogger;
use Stewart\Testing\Time\EventLoopTicks;

#[CoversClass(WorkerHandle::class)]
final class WorkerHandleTest extends TestCase
{
    public function testRelaysOutputLinesToLogger(): void
    {
        $logger = new RecordingLogger();
        $process = new FakeWorkerProcess(stdout: "first line\n\nsecond line\n");
        $handle = new WorkerHandle(new WorkerId(0), $process, new WorkerSlot(new WorkerId(0), AppDefinitionCollection::keyedByAppId([new AppDefinition(new AppId('demo'), Demo::class)])), $logger, new OutboxLimits(10, 256));

        $handle->relayOutput();
        EventLoopTicks::settleUntil(static fn(): bool => \count($logger->listMessagesAt('notice')) === 2);

        self::assertSame(['first line', 'second line'], $logger->listMessagesAt('notice'));
        self::assertSame(['worker' => 0, 'stream' => 'stdout'], $logger->records->getFirst()?->context);
    }

    public function testFailedSendTerminatesHandle(): void
    {
        $logger = new RecordingLogger();
        $process = new FakeWorkerProcess();
        $process->channel->refuseSendsOf(Shutdown::class, TransportException::sendFailed(new RuntimeException('broken pipe')));
        $handle = new WorkerHandle(new WorkerId(0), $process, new WorkerSlot(new WorkerId(0), AppDefinitionCollection::keyedByAppId([new AppDefinition(new AppId('demo'), Demo::class)])), $logger, new OutboxLimits(10, 256));

        $handle->send(new Shutdown('stop', Duration::zero()));
        EventLoopTicks::settleUntil(static fn(): bool => $handle->isTerminated());

        self::assertTrue($handle->isTerminated());
        self::assertTrue($process->closed);
        self::assertSame(['Failed sending to worker; terminating it'], $logger->listMessagesAt('warning'));
    }

    public function testStoppingHandleDropsLaterMessages(): void
    {
        $process = new FakeWorkerProcess();
        $handle = self::createHandle($process, new RecordingLogger());

        $handle->sendShutdown(new Shutdown('stop', Duration::zero()));
        $handle->send(new Ping(1, Instant::fromEpochMicroseconds(0)));
        EventLoopTicks::settle();

        self::assertTrue($handle->isStopping());
        self::assertSame([Shutdown::class], array_map(static fn(object $message): string => $message::class, $process->channel->sent));
    }

    public function testFailedSendToStoppingWorkerKeepsHandle(): void
    {
        $logger = new RecordingLogger();
        $process = new FakeWorkerProcess();
        $process->channel->refuseSendsOf(Shutdown::class, TransportException::sendFailed(new RuntimeException('broken pipe')));
        $handle = self::createHandle($process, $logger);

        $handle->sendShutdown(new Shutdown('stop', Duration::zero()));
        EventLoopTicks::settleUntil(static fn(): bool => $logger->listMessagesAt('debug') !== []);

        self::assertTrue($handle->isStopping());
        self::assertFalse($process->closed);
        self::assertSame([], $logger->listMessagesAt('warning'));
        self::assertSame(['Stopped sending to a stopping worker'], $logger->listMessagesAt('debug'));
    }

    private static function createHandle(FakeWorkerProcess $process, RecordingLogger $logger): WorkerHandle
    {
        return new WorkerHandle(new WorkerId(0), $process, new WorkerSlot(new WorkerId(0), AppDefinitionCollection::keyedByAppId([new AppDefinition(new AppId('demo'), Demo::class)])), $logger, new OutboxLimits(10, 256));
    }
}
