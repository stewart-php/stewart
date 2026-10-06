<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Integration\Worker;

use Amp\Future;
use Amp\TimeoutCancellation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\App\AppId;
use Stewart\Contracts\App\Collection\AppIdCollection;
use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\State\EntityState;
use Stewart\Contracts\State\StateChange;
use Stewart\Runtime\App\AppCatalog;
use Stewart\Runtime\App\AppDefinition;
use Stewart\Runtime\App\Collection\AppDefinitionCollection;
use Stewart\Runtime\Broker\AppPauseRegistry;
use Stewart\Runtime\Broker\AppPauseService;
use Stewart\Runtime\Broker\Collection\WorkerSlotCollection;
use Stewart\Runtime\Broker\DaemonStartTime;
use Stewart\Runtime\Broker\OutboxLimits;
use Stewart\Runtime\Broker\WorkerSlot;
use Stewart\Runtime\Ipc\Wire\IpcCodec;
use Stewart\Runtime\Kernel\SyntheticServices;
use Stewart\Runtime\Lifecycle\AppPauseSource;
use Stewart\Runtime\Model\WorkerId;
use Stewart\Runtime\Tests\Fixtures\Broker\BrokerKernelFixture;
use Stewart\Runtime\Tests\Fixtures\Broker\FakeHaSession;
use Stewart\Runtime\Tests\Fixtures\Broker\ReceivedServiceCall;
use Stewart\Runtime\Tests\Fixtures\Broker\WorkerPoolFixture;
use Stewart\Runtime\Tests\Fixtures\Protocol\SerialHandler;
use Stewart\Runtime\Tests\Fixtures\Worker\InMemoryWorkerSpawner;
use Stewart\Testing\Logging\RecordingLogger;
use Stewart\Testing\Time\VirtualClock;

use function Amp\async;

#[CoversClass(AppPauseService::class)]
final class AppPauseRoundTripTest extends TestCase
{
    private const float WAIT_SECONDS = 5;

    public function testPausedAppSkipsChangesUntilResumed(): void
    {
        $session = new FakeHaSession();
        $logger = new RecordingLogger();
        $pausedApps = new AppPauseRegistry(AppDefinitionCollection::keyedByAppId([]), new DaemonStartTime(new VirtualClock()));
        $pools = WorkerPoolFixture::createWorkerPool(new InMemoryWorkerSpawner(IpcCodec::createForWorkerBootstrap()), logger: $logger, outboxLimits: new OutboxLimits(100, 256));
        $appId = new AppId('serial-handler');
        $apps = AppDefinitionCollection::keyedByAppId([new AppDefinition($appId, SerialHandler::class)]);
        $broker = BrokerKernelFixture::boot(
            $session,
            $pools,
            WorkerSlotCollection::fromWorkerSlots([new WorkerSlot(new WorkerId(0), $apps)]),
            AppIdCollection::fromIds([$appId]),
            $logger,
            ['shutdown_grace' => '1s'],
            new SyntheticServices()->withService(AppPauseRegistry::class, $pausedApps),
        );
        $service = new AppPauseService(new AppCatalog($apps, AppIdCollection::fromIds([$appId]), AppIdCollection::fromIds([])), $pausedApps, $pools->slots, new VirtualClock(), $logger);
        $ready = $logger->waitForMessage('Worker ready');

        /** @var Future<null> $running */
        $running = async($broker->lifecycle->run(...));
        $ready->await(new TimeoutCancellation(self::WAIT_SECONDS));

        self::assertTrue($service->pauseApp($appId, AppPauseSource::Control));
        self::assertFalse($service->pauseApp($appId, AppPauseSource::Control), 'Pausing a paused app changes nothing.');
        $this->changeSerial($session, '1', '2');

        self::assertTrue($service->resumeApp($appId, AppPauseSource::Control));
        $called = $session->waitForNextCall();
        $this->changeSerial($session, '2', '3');
        $called->await(new TimeoutCancellation(self::WAIT_SECONDS));

        self::assertCount(1, $session->receivedCalls);
        self::assertSame(['value' => '3'], $session->receivedCalls[0]->data);

        $broker->run->stop('test done');
        $running->await(new TimeoutCancellation(self::WAIT_SECONDS));
    }

    public function testAppConfiguredPausedHandlesNothing(): void
    {
        $session = new FakeHaSession();
        $logger = new RecordingLogger();
        $pools = WorkerPoolFixture::createWorkerPool(new InMemoryWorkerSpawner(IpcCodec::createForWorkerBootstrap()), logger: $logger, outboxLimits: new OutboxLimits(100, 256));
        $running = new AppId('serial-handler');
        $paused = new AppId('serial-paused');
        $broker = BrokerKernelFixture::boot(
            $session,
            $pools,
            WorkerSlotCollection::fromWorkerSlots([new WorkerSlot(new WorkerId(0), AppDefinitionCollection::keyedByAppId([
                new AppDefinition($running, SerialHandler::class),
                new AppDefinition($paused, SerialHandler::class, startsPaused: true),
            ]))]),
            AppIdCollection::fromIds([$running, $paused]),
            $logger,
            ['shutdown_grace' => '1s'],
        );
        $ready = $logger->waitForMessage('Worker ready');

        /** @var Future<null> $run */
        $run = async($broker->lifecycle->run(...));
        $ready->await(new TimeoutCancellation(self::WAIT_SECONDS));

        foreach (['2', '3'] as $value) {
            $called = $session->waitForNextCall();
            $this->changeSerial($session, '1', $value);
            $called->await(new TimeoutCancellation(self::WAIT_SECONDS));
        }

        self::assertSame([['value' => '2'], ['value' => '3']], array_map(static fn(ReceivedServiceCall $call): array => $call->data, $session->receivedCalls));

        $broker->run->stop('test done');
        $run->await(new TimeoutCancellation(self::WAIT_SECONDS));
    }

    private function changeSerial(FakeHaSession $session, string $from, string $to): void
    {
        $entityId = new EntityId(SerialHandler::ENTITY);

        $session->listener?->stateChanged(new StateChange($entityId, new EntityState($entityId, $from), new EntityState($entityId, $to)));
    }
}
