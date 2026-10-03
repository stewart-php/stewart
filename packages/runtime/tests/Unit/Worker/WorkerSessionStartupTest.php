<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Worker;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\App\AppId;
use Stewart\Contracts\State\Collection\EntityStateCollection;
use Stewart\Contracts\Time\Clock;
use Stewart\Contracts\Time\Duration;
use Stewart\Runtime\Exception\TransportException;
use Stewart\Runtime\Ipc\Message\LogRecord;
use Stewart\Runtime\Ipc\Message\Shutdown;
use Stewart\Runtime\Ipc\Message\StateSnapshot;
use Stewart\Runtime\Ipc\Message\WorkerReady;
use Stewart\Runtime\Ipc\Wire\EntityStatesFragment;
use Stewart\Runtime\Ipc\WorkerApp;
use Stewart\Runtime\Kernel\SyntheticServices;
use Stewart\Runtime\Kernel\WorkerKernel;
use Stewart\Runtime\Model\LogLevel;
use Stewart\Runtime\Tests\Fixtures\Ipc\FakeWorkerTransport;
use Stewart\Runtime\Tests\Fixtures\Ipc\TestBootstrap;
use Stewart\Runtime\Tests\Fixtures\Lifecycle\Healthy;
use Stewart\Runtime\Tests\Fixtures\Time\RecordingProcessTimeZone;
use Stewart\Runtime\Time\ProcessTimeZone;
use Stewart\Runtime\Worker\WorkerSession;
use Stewart\Support\Time\RevoltTimers;
use Stewart\Testing\Time\EventLoopTicks;
use Stewart\Testing\Time\ManualTimers;

use function Amp\async;

#[CoversClass(WorkerSession::class)]
final class WorkerSessionStartupTest extends TestCase
{
    public function testReadyReportOnAClosedChannelIsLogged(): void
    {
        $transport = new FakeWorkerTransport();
        $timers = new ManualTimers();
        $kernel = new WorkerKernel(new SyntheticServices()
            ->withService(RevoltTimers::class, $timers)
            ->withService(Clock::class, $timers->clock)
            ->withService(ProcessTimeZone::class, new RecordingProcessTimeZone()));

        $transport->refuseSendsOf(WorkerReady::class, TransportException::closed());
        $transport->deliver(TestBootstrap::createForApps([new WorkerApp(id: new AppId('healthy'), class: Healthy::class, options: [])]));

        $running = async(fn(): string => $kernel->run($transport));
        $transport->deliver(new StateSnapshot(EntityStatesFragment::fromCollection(EntityStateCollection::keyedByEntityId([])), revision: 1));
        EventLoopTicks::settle();

        $transport->deliver(new Shutdown('test over', Duration::zero()));
        $running->await();

        $debug = array_filter(
            $transport->listSentOfType(LogRecord::class),
            static fn(LogRecord $log): bool => $log->level === LogLevel::Debug && $log->message === 'Could not report ready; the broker channel is closed',
        );

        self::assertCount(1, $debug);
    }
}
