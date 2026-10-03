<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Process;

use Amp\Parallel\Context\ProcessContextFactory;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Service\ServiceResponse;
use Stewart\Contracts\State\Collection\EntityStateCollection;
use Stewart\Contracts\Time\Duration;
use Stewart\Runtime\Broker\ProcessWorkerSpawner;
use Stewart\Runtime\Ipc\Message\AppFailed;
use Stewart\Runtime\Ipc\Message\LogRecord;
use Stewart\Runtime\Ipc\Message\Ping;
use Stewart\Runtime\Ipc\Message\Pong;
use Stewart\Runtime\Ipc\Message\ServiceCallRequest;
use Stewart\Runtime\Ipc\Message\ServiceCallResult;
use Stewart\Runtime\Ipc\Message\Shutdown;
use Stewart\Runtime\Ipc\Message\WorkerReady;
use Stewart\Runtime\Ipc\Wire\EntityStatesFragment;
use Stewart\Runtime\Ipc\Wire\IpcCodec;
use Stewart\Runtime\Ipc\WorkerApp;
use Stewart\Runtime\Tests\Fixtures\Ipc\TestBootstrap;
use Stewart\Runtime\Tests\Fixtures\Protocol\DisposeCaller;
use Stewart\Runtime\Tests\Fixtures\Protocol\HangsOnDispose;
use Stewart\Runtime\Tests\Fixtures\Protocol\ThrowingTimer;
use Stewart\Runtime\Tests\Fixtures\Protocol\UnawaitedFailure;
use Stewart\Runtime\Tests\Fixtures\Protocol\WorkerHarness;
use Stewart\Runtime\Time\SystemClock;

#[CoversNothing]
final class WorkerFaultIsolationProcessTest extends TestCase
{
    private const float GRACE_SECONDS = 1;

    private const float STOP_BUDGET_SECONDS = 3;

    private ?WorkerHarness $worker = null;

    protected function tearDown(): void
    {
        $this->worker?->close();
        $this->worker = null;
    }

    public function testChildSurvivesThrowingTimerAndLostFuture(): void
    {
        $this->worker = WorkerHarness::boot(
            new ProcessWorkerSpawner(new ProcessContextFactory(), IpcCodec::createForWorkerBootstrap()),
            TestBootstrap::createForApps([
                new WorkerApp(id: new AppId('throwing-timer'), class: ThrowingTimer::class, options: []),
                new WorkerApp(id: new AppId('unawaited-failure'), class: UnawaitedFailure::class, options: []),
            ]),
            EntityStatesFragment::fromCollection(EntityStateCollection::keyedByEntityId([])),
        );

        $failures = [$this->worker->receiveUntil(AppFailed::class)];
        $failures[] = $this->worker->receiveUntil(AppFailed::class);
        $origins = array_map(static fn(AppFailed $failure): string => $failure->scope->wireValue() . ' ' . $failure->message, $failures);
        sort($origins);

        self::assertCount(2, $origins);
        self::assertStringStartsWith('@shared ', $origins[0]);
        self::assertStringContainsString(UnawaitedFailure::FAILURE, $origins[0]);
        self::assertSame('throwing-timer ' . ThrowingTimer::FAILURE, $origins[1]);

        $this->worker->send(new Ping(nonce: 7, sentAt: SystemClock::inUtc()->getNow()));
        self::assertSame(7, $this->worker->receiveUntil(Pong::class)->nonce);

        $summary = $this->worker->shutDown('done');
        $this->worker = null;

        self::assertStringContainsString('worker 0 stopped (done', $summary);
    }

    public function testHangingDisposeLeavesNeighboursDisposed(): void
    {
        $this->worker = WorkerHarness::boot(
            new ProcessWorkerSpawner(new ProcessContextFactory(), IpcCodec::createForWorkerBootstrap()),
            TestBootstrap::createForApps([
                new WorkerApp(id: new AppId('hangs-on-dispose'), class: HangsOnDispose::class, options: []),
                new WorkerApp(id: new AppId('dispose-caller'), class: DisposeCaller::class, options: []),
            ]),
            EntityStatesFragment::fromCollection(EntityStateCollection::keyedByEntityId([])),
        );
        $this->worker->receiveUntil(WorkerReady::class);

        $startedAt = hrtime(true);
        $this->worker->send(new Shutdown('done', Duration::seconds(self::GRACE_SECONDS)));
        $request = $this->worker->receiveUntil(ServiceCallRequest::class);
        $this->worker->send(new ServiceCallResult($request->correlationId, new ServiceResponse('light', 'turn_off')));
        $this->worker->receiveUntil(LogRecord::class, static fn(LogRecord $log): bool => $log->message === 'Dispose call finished');
        $abandoned = $this->worker->receiveUntil(LogRecord::class, static fn(LogRecord $log): bool => $log->message === 'Apps did not stop within the grace period');

        $summary = $this->worker->join();
        $this->worker = null;

        self::assertLessThan(self::STOP_BUDGET_SECONDS, (hrtime(true) - $startedAt) / 1e9);
        self::assertSame(['hangs-on-dispose'], $abandoned->context['apps'] ?? null);
        self::assertStringContainsString('worker 0 stopped (done', $summary);
    }
}
