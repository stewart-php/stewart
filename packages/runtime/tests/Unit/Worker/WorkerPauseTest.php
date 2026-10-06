<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Worker;

use Amp\Future;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\App\AppId;
use Stewart\Contracts\App\Collection\AppIdCollection;
use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\State\Collection\EntityStateCollection;
use Stewart\Contracts\State\Collection\StateChangeCollection;
use Stewart\Contracts\State\EntityState;
use Stewart\Contracts\State\StateChange;
use Stewart\Contracts\Time\Clock;
use Stewart\Contracts\Time\Duration;
use Stewart\Runtime\Ipc\Message\LogRecord;
use Stewart\Runtime\Ipc\Message\PausedAppsChanged;
use Stewart\Runtime\Ipc\Message\Shutdown;
use Stewart\Runtime\Ipc\Message\StateChangeBatch;
use Stewart\Runtime\Ipc\Message\StateSnapshot;
use Stewart\Runtime\Ipc\Message\WorkerReady;
use Stewart\Runtime\Ipc\Wire\AppIdsFragment;
use Stewart\Runtime\Ipc\Wire\EntityStatesFragment;
use Stewart\Runtime\Ipc\Wire\StateChangesFragment;
use Stewart\Runtime\Ipc\WorkerApp;
use Stewart\Runtime\Kernel\SyntheticServices;
use Stewart\Runtime\Kernel\WorkerKernel;
use Stewart\Runtime\Tests\Fixtures\Ipc\FakeWorkerTransport;
use Stewart\Runtime\Tests\Fixtures\Ipc\TestBootstrap;
use Stewart\Runtime\Tests\Fixtures\Lifecycle\SubscribesThenWaits;
use Stewart\Runtime\Tests\Fixtures\Time\RecordingProcessTimeZone;
use Stewart\Runtime\Time\ProcessTimeZone;
use Stewart\Runtime\Worker\Message\PausedAppsChangedHandler;
use Stewart\Runtime\Worker\PausedAppsSync;
use Stewart\Runtime\Worker\WorkerSession;
use Stewart\Support\Time\RevoltTimers;
use Stewart\Testing\Time\EventLoopTicks;
use Stewart\Testing\Time\ManualTimers;

use function Amp\async;

#[CoversClass(PausedAppsSync::class)]
#[CoversClass(PausedAppsChangedHandler::class)]
#[CoversClass(WorkerSession::class)]
final class WorkerPauseTest extends TestCase
{
    private const string APP_ID = 'subscriber';

    private FakeWorkerTransport $transport;

    /** @var Future<string>|null */
    private ?Future $running = null;

    protected function setUp(): void
    {
        $this->transport = new FakeWorkerTransport();
        SubscribesThenWaits::reset();
        SubscribesThenWaits::open();
    }

    protected function tearDown(): void
    {
        $this->transport->deliver(new Shutdown('test over', Duration::zero()));
        $this->running?->await();
    }

    public function testAppPausedAtBootstrapHandlesNothing(): void
    {
        $this->startWorker(self::APP_ID);

        $this->sendHallChange();

        self::assertNotContains('handled light.hall', SubscribesThenWaits::$log);
    }

    public function testResumedAppHandlesNewChanges(): void
    {
        $this->startWorker(self::APP_ID);
        $this->sendHallChange();

        $this->transport->deliver(new PausedAppsChanged(AppIdsFragment::fromCollection(AppIdCollection::empty())));
        $this->sendHallChange();

        self::assertSame(['handled light.hall'], array_values(array_filter(SubscribesThenWaits::$log, static fn(string $line): bool => str_starts_with($line, 'handled'))));
    }

    public function testRepeatedPausedSetPausesOnce(): void
    {
        $this->startWorker();

        foreach ([1, 2] as $ignored) {
            $this->transport->deliver(new PausedAppsChanged(AppIdsFragment::fromCollection(AppIdCollection::fromIds([new AppId(self::APP_ID), new AppId('elsewhere')]))));
            EventLoopTicks::settle();
        }

        $this->sendHallChange();

        self::assertNotContains('handled light.hall', SubscribesThenWaits::$log);
        self::assertCount(1, array_filter($this->transport->listSentOfType(LogRecord::class), static fn(LogRecord $log): bool => $log->message === 'App paused'));
    }

    private function startWorker(string ...$pausedAppIds): void
    {
        $timers = new ManualTimers();
        $kernel = new WorkerKernel(new SyntheticServices()
            ->withService(RevoltTimers::class, $timers)
            ->withService(Clock::class, $timers->clock)
            ->withService(ProcessTimeZone::class, new RecordingProcessTimeZone()));
        $this->transport->deliver(TestBootstrap::createForApps(
            [new WorkerApp(id: new AppId(self::APP_ID), class: SubscribesThenWaits::class, options: [])],
            pausedAppIds: AppIdCollection::fromIds(array_map(static fn(string $id): AppId => new AppId($id), $pausedAppIds)),
        ));

        /** @var Future<string> $running */
        $running = async(fn(): string => $kernel->run($this->transport));
        $this->running = $running;
        $this->transport->deliver(new StateSnapshot(EntityStatesFragment::fromCollection(EntityStateCollection::keyedByEntityId([])), revision: 1));
        EventLoopTicks::settleUntil(fn(): bool => $this->transport->listSentOfType(WorkerReady::class) !== []);
    }

    private function sendHallChange(): void
    {
        $id = new EntityId('light.hall');
        $change = new StateChange($id, new EntityState($id, 'off'), new EntityState($id, 'on'));

        $this->transport->deliver(new StateChangeBatch(StateChangesFragment::fromCollection(StateChangeCollection::fromChanges([$change]))));
        EventLoopTicks::settle();
    }
}
