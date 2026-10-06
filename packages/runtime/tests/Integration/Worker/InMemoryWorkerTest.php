<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Integration\Worker;

use Amp\Future;
use Amp\TimeoutCancellation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\App;
use Stewart\Contracts\App\AppId;
use Stewart\Contracts\App\Collection\AppIdCollection;
use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\State\EntityState;
use Stewart\Contracts\State\StateChange;
use Stewart\Runtime\App\AppDefinition;
use Stewart\Runtime\App\Collection\AppDefinitionCollection;
use Stewart\Runtime\Broker\Collection\WorkerSlotCollection;
use Stewart\Runtime\Broker\OutboxLimits;
use Stewart\Runtime\Broker\WorkerPool;
use Stewart\Runtime\Broker\WorkerSlot;
use Stewart\Runtime\Ipc\Wire\IpcCodec;
use Stewart\Runtime\Model\WorkerId;
use Stewart\Runtime\Tests\Fixtures\Broker\BootedBroker;
use Stewart\Runtime\Tests\Fixtures\Broker\BrokerKernelFixture;
use Stewart\Runtime\Tests\Fixtures\Broker\FakeHaSession;
use Stewart\Runtime\Tests\Fixtures\Broker\ReceivedEventFire;
use Stewart\Runtime\Tests\Fixtures\Broker\WorkerPoolFixture;
use Stewart\Runtime\Tests\Fixtures\Protocol\HistoryReporter;
use Stewart\Runtime\Tests\Fixtures\Protocol\InitFirer;
use Stewart\Runtime\Tests\Fixtures\Protocol\SerialHandler;
use Stewart\Runtime\Tests\Fixtures\Worker\InMemoryWorkerSpawner;
use Stewart\Testing\Logging\RecordedLog;
use Stewart\Testing\Logging\RecordingLogger;

use function Amp\async;

#[CoversClass(WorkerPool::class)]
final class InMemoryWorkerTest extends TestCase
{
    private const float WAIT_SECONDS = 5;

    public function testStateChangeRoundTripsToServiceCall(): void
    {
        $session = new FakeHaSession();
        $logger = new RecordingLogger();
        $pools = WorkerPoolFixture::createWorkerPool(new InMemoryWorkerSpawner(IpcCodec::createForWorkerBootstrap()), logger: $logger, outboxLimits: new OutboxLimits(100, 256));
        $broker = self::createBroker($session, $pools, $logger);

        $ready = $logger->waitForMessage('Worker ready');

        /** @var Future<null> $running */
        $running = async($broker->lifecycle->run(...));

        $ready->await(new TimeoutCancellation(self::WAIT_SECONDS));

        $called = $session->waitForNextCall();
        $session->listener?->stateChanged(new StateChange(
            new EntityId(SerialHandler::ENTITY),
            new EntityState(new EntityId(SerialHandler::ENTITY), '1'),
            new EntityState(new EntityId(SerialHandler::ENTITY), '2'),
        ));

        $called->await(new TimeoutCancellation(self::WAIT_SECONDS));
        self::assertSame(1, $session->calls);

        $broker->run->stop('test done');
        $running->await(new TimeoutCancellation(self::WAIT_SECONDS));

        self::assertSame(0, $pools->slots->countLiveWorkers());
    }

    public function testAppReadsHistoryThroughBroker(): void
    {
        $session = new FakeHaSession();
        $session->historicalStates = [
            new EntityState(new EntityId(HistoryReporter::ENTITY), 'off'),
            new EntityState(new EntityId(HistoryReporter::ENTITY), 'on'),
            new EntityState(new EntityId(HistoryReporter::ENTITY), 'off'),
        ];
        $logger = new RecordingLogger();
        $pools = WorkerPoolFixture::createWorkerPool(new InMemoryWorkerSpawner(IpcCodec::createForWorkerBootstrap()), logger: $logger, outboxLimits: new OutboxLimits(100, 256));
        $broker = self::createBroker($session, $pools, $logger, new AppId('history-reporter'), HistoryReporter::class);

        $ready = $logger->waitForMessage('Worker ready');

        /** @var Future<null> $running */
        $running = async($broker->lifecycle->run(...));

        $ready->await(new TimeoutCancellation(self::WAIT_SECONDS));

        $read = $logger->waitForMessage('History read');
        $session->listener?->stateChanged(new StateChange(
            new EntityId(HistoryReporter::ENTITY),
            new EntityState(new EntityId(HistoryReporter::ENTITY), 'on'),
            new EntityState(new EntityId(HistoryReporter::ENTITY), 'off'),
        ));

        $read->await(new TimeoutCancellation(self::WAIT_SECONDS));
        $record = $logger->records->findFirstWhere(static fn(RecordedLog $log): bool => $log->message === 'History read');
        self::assertSame(2, $record?->context['changes'] ?? null);
        self::assertTrue($record->context['was_on'] ?? null);

        $broker->run->stop('test done');
        $running->await(new TimeoutCancellation(self::WAIT_SECONDS));
    }

    public function testAppFiresEventThroughBroker(): void
    {
        $session = new FakeHaSession();
        $logger = new RecordingLogger();
        $pools = WorkerPoolFixture::createWorkerPool(new InMemoryWorkerSpawner(IpcCodec::createForWorkerBootstrap()), logger: $logger, outboxLimits: new OutboxLimits(100, 256));
        $broker = self::createBroker($session, $pools, $logger, new AppId('init-firer'), InitFirer::class);

        $fired = $logger->waitForMessage('Initialize fire finished');

        /** @var Future<null> $running */
        $running = async($broker->lifecycle->run(...));

        $fired->await(new TimeoutCancellation(self::WAIT_SECONDS));
        $record = $logger->records->findFirstWhere(static fn(RecordedLog $log): bool => $log->message === 'Initialize fire finished');
        self::assertSame(FakeHaSession::FIRE_CONTEXT_PREFIX . '1', $record?->context['context'] ?? null);
        self::assertEquals([new ReceivedEventFire(InitFirer::EVENT_TYPE, ['button' => 'front'])], $session->receivedEventFires);

        $broker->run->stop('test done');
        $running->await(new TimeoutCancellation(self::WAIT_SECONDS));
    }

    /** @param class-string<App> $appClass */
    private static function createBroker(
        FakeHaSession $session,
        WorkerPoolFixture $pools,
        RecordingLogger $logger,
        AppId $appId = new AppId('serial-handler'),
        string $appClass = SerialHandler::class,
    ): BootedBroker {
        return BrokerKernelFixture::boot(
            $session,
            $pools,
            WorkerSlotCollection::fromWorkerSlots([new WorkerSlot(new WorkerId(0), AppDefinitionCollection::keyedByAppId([new AppDefinition($appId, $appClass)]))]),
            AppIdCollection::fromIds([$appId]),
            $logger,
            ['shutdown_grace' => '1s'],
        );
    }
}
