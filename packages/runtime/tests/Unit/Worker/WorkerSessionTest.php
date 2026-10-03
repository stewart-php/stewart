<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Worker;

use Amp\Future;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\State\Collection\EntityStateCollection;
use Stewart\Contracts\State\EntityState;
use Stewart\Contracts\Time\Clock;
use Stewart\Contracts\Time\Duration;
use Stewart\Runtime\Ipc\Message\HaConnectionLost;
use Stewart\Runtime\Ipc\Message\LogRecord;
use Stewart\Runtime\Ipc\Message\Shutdown;
use Stewart\Runtime\Ipc\Message\StateResynced;
use Stewart\Runtime\Ipc\Message\StateSnapshot;
use Stewart\Runtime\Ipc\Message\WorkerReady;
use Stewart\Runtime\Ipc\Wire\EntityStatesFragment;
use Stewart\Runtime\Ipc\WorkerApp;
use Stewart\Runtime\Kernel\SyntheticServices;
use Stewart\Runtime\Kernel\WorkerKernel;
use Stewart\Runtime\Tests\Fixtures\Ipc\FakeWorkerTransport;
use Stewart\Runtime\Tests\Fixtures\Ipc\TestBootstrap;
use Stewart\Runtime\Tests\Fixtures\Protocol\OutageWatcher;
use Stewart\Runtime\Tests\Fixtures\Time\RecordingProcessTimeZone;
use Stewart\Runtime\Time\ProcessTimeZone;
use Stewart\Runtime\Worker\BrokerMessageReader;
use Stewart\Runtime\Worker\Message\BrokerMessageDispatcher;
use Stewart\Runtime\Worker\Message\HaConnectionLostHandler;
use Stewart\Runtime\Worker\Message\StateResyncedHandler;
use Stewart\Runtime\Worker\StateCacheSync;
use Stewart\Runtime\Worker\WorkerSession;
use Stewart\Runtime\Worker\WorkerShutdown;
use Stewart\Support\Time\RevoltTimers;
use Stewart\Testing\Time\EventLoopTicks;
use Stewart\Testing\Time\ManualTimers;

use function Amp\async;

#[CoversClass(WorkerSession::class)]
#[CoversClass(WorkerShutdown::class)]
#[CoversClass(StateCacheSync::class)]
#[CoversClass(BrokerMessageReader::class)]
#[CoversClass(BrokerMessageDispatcher::class)]
#[CoversClass(StateResyncedHandler::class)]
#[CoversClass(HaConnectionLostHandler::class)]
final class WorkerSessionTest extends TestCase
{
    private const string HALL = 'light.hall';

    private const string PORCH = 'light.porch';

    private FakeWorkerTransport $transport;

    private ManualTimers $timers;

    /** @var Future<string> */
    private Future $running;

    protected function setUp(): void
    {
        $this->transport = new FakeWorkerTransport();
        $this->timers = new ManualTimers();

        $kernel = new WorkerKernel(new SyntheticServices()
            ->withService(RevoltTimers::class, $this->timers)
            ->withService(Clock::class, $this->timers->clock)
            ->withService(ProcessTimeZone::class, new RecordingProcessTimeZone()));
        $this->transport->deliver(TestBootstrap::createForApps([new WorkerApp(id: new AppId('outage-watcher'), class: OutageWatcher::class, options: [])]));

        /** @var Future<string> $running */
        $running = async(fn(): string => $kernel->run($this->transport));
        $this->running = $running;
        $this->transport->deliver(new StateSnapshot(self::createStates('off', 'off'), revision: 1));
        EventLoopTicks::settleUntil(fn(): bool => \count($this->transport->listSentOfType(WorkerReady::class)) === 1);

        self::assertCount(1, $this->transport->listSentOfType(WorkerReady::class));
    }

    protected function tearDown(): void
    {
        $this->transport->deliver(new Shutdown('test over', Duration::zero()));
        $this->running->await();
    }

    public function testStaleResyncEndsOutageWithoutReseeding(): void
    {
        $this->loseConnection();

        $this->transport->deliver(new StateResynced(self::createStates('on', 'on'), revision: 1, outage: Duration::seconds(3)));
        EventLoopTicks::settleUntil(fn(): bool => $this->listRestorations() !== []);

        self::assertSame([['entities' => 2, 'outage' => '3s', 'reconstructed' => 0]], $this->listRestorations());
    }

    public function testFreshResyncReplaysWhatChangedDuringTheOutage(): void
    {
        $this->loseConnection();

        $this->transport->deliver(new StateResynced(self::createStates('on', 'off'), revision: 2, outage: Duration::seconds(3)));
        EventLoopTicks::settleUntil(fn(): bool => $this->listRestorations() !== []);

        self::assertSame([['entities' => 2, 'outage' => '3s', 'reconstructed' => 1]], $this->listRestorations());
    }

    public function testStaleResyncWithoutAnOutageAnnouncesNothing(): void
    {
        $this->transport->deliver(new StateResynced(self::createStates('on', 'on'), revision: 1, outage: Duration::zero()));
        EventLoopTicks::settle();

        self::assertSame([], $this->listRestorations());
    }

    private function loseConnection(): void
    {
        $this->transport->deliver(new HaConnectionLost($this->timers->clock->getNow(), 'websocket closed'));
        EventLoopTicks::settle();
    }

    /** @return list<array<string, mixed>> */
    private function listRestorations(): array
    {
        $restored = array_filter(
            $this->transport->listSentOfType(LogRecord::class),
            static fn(LogRecord $log): bool => $log->message === 'State resynced',
        );

        return array_values(array_map(static fn(LogRecord $log): array => $log->context, $restored));
    }

    private static function createStates(string $hall, string $porch): EntityStatesFragment
    {
        return EntityStatesFragment::fromCollection(EntityStateCollection::keyedByEntityId([new EntityState(new EntityId(self::HALL), $hall), new EntityState(new EntityId(self::PORCH), $porch)]));
    }
}
