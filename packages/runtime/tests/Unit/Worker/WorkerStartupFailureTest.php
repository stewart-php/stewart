<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Worker;

use Amp\Future;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\App\AppId;
use Stewart\Contracts\State\Collection\EntityStateCollection;
use Stewart\Contracts\Time\Clock;
use Stewart\Runtime\Ipc\Message\LogRecord;
use Stewart\Runtime\Ipc\Message\StateSnapshot;
use Stewart\Runtime\Ipc\Message\WorkerReady;
use Stewart\Runtime\Ipc\Wire\EntityStatesFragment;
use Stewart\Runtime\Ipc\WorkerApp;
use Stewart\Runtime\Kernel\SyntheticServices;
use Stewart\Runtime\Kernel\WorkerKernel;
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
final class WorkerStartupFailureTest extends TestCase
{
    public function testUnexpectedStartupErrorStopsWorker(): void
    {
        $transport = new FakeWorkerTransport();
        $timers = new ManualTimers();
        $transport->refuseSendsOf(WorkerReady::class, new LogicException('bug while reporting ready'));
        $kernel = new WorkerKernel(new SyntheticServices()
            ->withService(RevoltTimers::class, $timers)
            ->withService(Clock::class, $timers->clock)
            ->withService(ProcessTimeZone::class, new RecordingProcessTimeZone()));
        $transport->deliver(TestBootstrap::createForApps([new WorkerApp(id: new AppId('healthy'), class: Healthy::class, options: [])]));

        /** @var Future<string> $running */
        $running = async(static fn(): string => $kernel->run($transport));
        $transport->deliver(new StateSnapshot(EntityStatesFragment::fromCollection(EntityStateCollection::keyedByEntityId([])), revision: 1));
        EventLoopTicks::settleUntil(static fn(): bool => $running->isComplete());

        self::assertTrue($running->isComplete(), 'The worker exits so the pool restarts it.');
        self::assertStringContainsString('startup failed', $running->await());
        self::assertContains('Worker failed starting its apps', array_map(static fn(LogRecord $log): string => $log->message, $transport->listSentOfType(LogRecord::class)));
    }
}
