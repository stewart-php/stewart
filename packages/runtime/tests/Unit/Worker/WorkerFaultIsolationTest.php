<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Worker;

use Amp\Future;
use Amp\Future\UnhandledFutureError;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\App\AppId;
use Stewart\Contracts\State\Collection\EntityStateCollection;
use Stewart\Contracts\Time\Clock;
use Stewart\Contracts\Time\Duration;
use Stewart\Runtime\Ipc\Message\AppFailed;
use Stewart\Runtime\Ipc\Message\Ping;
use Stewart\Runtime\Ipc\Message\Pong;
use Stewart\Runtime\Ipc\Message\Shutdown;
use Stewart\Runtime\Ipc\Message\StateSnapshot;
use Stewart\Runtime\Ipc\Message\WorkerReady;
use Stewart\Runtime\Ipc\Wire\EntityStatesFragment;
use Stewart\Runtime\Ipc\WorkerApp;
use Stewart\Runtime\Kernel\SyntheticServices;
use Stewart\Runtime\Kernel\WorkerKernel;
use Stewart\Runtime\Lifecycle\AppFailurePhase;
use Stewart\Runtime\Schedule\ScheduledTimerHandle;
use Stewart\Runtime\Schedule\WorkerTimers;
use Stewart\Runtime\Tests\Fixtures\Ipc\FakeWorkerTransport;
use Stewart\Runtime\Tests\Fixtures\Ipc\TestBootstrap;
use Stewart\Runtime\Tests\Fixtures\Lifecycle\Healthy;
use Stewart\Runtime\Tests\Fixtures\Protocol\ThrowingSchedule;
use Stewart\Runtime\Tests\Fixtures\Protocol\ThrowingTimer;
use Stewart\Runtime\Tests\Fixtures\Protocol\UnawaitedFailure;
use Stewart\Runtime\Tests\Fixtures\Time\RecordingProcessTimeZone;
use Stewart\Runtime\Time\ProcessTimeZone;
use Stewart\Runtime\Worker\AppFailureReporter;
use Stewart\Runtime\Worker\HandlerFailureSampler;
use Stewart\Runtime\Worker\LoopErrorReporter;
use Stewart\Support\Time\RevoltTimers;
use Stewart\Testing\Time\EventLoopTicks;
use Stewart\Testing\Time\ManualTimers;

use function Amp\async;

#[CoversClass(WorkerTimers::class)]
#[CoversClass(ScheduledTimerHandle::class)]
#[CoversClass(LoopErrorReporter::class)]
#[CoversClass(AppFailureReporter::class)]
#[CoversClass(HandlerFailureSampler::class)]
final class WorkerFaultIsolationTest extends TestCase
{
    private FakeWorkerTransport $transport;

    private ManualTimers $timers;

    /** @var Future<string>|null */
    private ?Future $running = null;

    protected function setUp(): void
    {
        $this->transport = new FakeWorkerTransport();
        $this->timers = new ManualTimers();
    }

    protected function tearDown(): void
    {
        $this->transport->deliver(new Shutdown('test over', Duration::zero()));
        $this->running?->await();
    }

    public function testThrowingAppTimerFailsOnlyThatApp(): void
    {
        $this->startWorkerWith(
            new WorkerApp(id: new AppId('throwing-timer'), class: ThrowingTimer::class, options: []),
            new WorkerApp(id: new AppId('healthy'), class: Healthy::class, options: []),
        );

        $this->timers->delay(Duration::seconds(1));
        EventLoopTicks::settleUntil(fn(): bool => $this->transport->listSentOfType(AppFailed::class) !== []);

        $failure = $this->transport->listSentOfType(AppFailed::class)[0];
        self::assertSame('throwing-timer', $failure->scope->wireValue());
        self::assertSame(AppFailurePhase::Handler, $failure->phase);
        self::assertSame(ThrowingTimer::FAILURE, $failure->message);
        self::assertSame(['throwing-timer', 'healthy'], $this->transport->listSentOfType(WorkerReady::class)[0]->appIds->collection->toStrings());
        $this->assertWorkerStillAnswers();
    }

    public function testUnawaitedFailedFutureIsReported(): void
    {
        $this->startWorkerWith(new WorkerApp(id: new AppId('unawaited-failure'), class: UnawaitedFailure::class, options: []));

        EventLoopTicks::settleUntil(fn(): bool => $this->transport->listSentOfType(AppFailed::class) !== []);

        $failure = $this->transport->listSentOfType(AppFailed::class)[0];
        self::assertTrue($failure->scope->isShared());
        self::assertSame('event loop', $failure->origin);
        self::assertSame(UnhandledFutureError::class, $failure->class);
        self::assertStringContainsString(UnawaitedFailure::FAILURE, $failure->message);
        $this->assertWorkerStillAnswers();
    }

    public function testRepeatedHandlerFailuresAreSampledAndCounted(): void
    {
        $this->startWorkerWith(new WorkerApp(id: new AppId('throwing-schedule'), class: ThrowingSchedule::class, options: []));

        for ($second = 0; $second < 150; ++$second) {
            $this->timers->delay(Duration::seconds(1));
            EventLoopTicks::settle();
        }

        self::assertSame([1, 100], array_map(static fn(AppFailed $failure): int => $failure->occurrence, $this->transport->listSentOfType(AppFailed::class)));
        $this->assertWorkerStillAnswers();
        self::assertSame(150, $this->transport->listSentOfType(Pong::class)[0]->apps[0]->failures);
    }

    private function startWorkerWith(WorkerApp ...$apps): void
    {
        $kernel = new WorkerKernel(new SyntheticServices()
            ->withService(RevoltTimers::class, $this->timers)
            ->withService(Clock::class, $this->timers->clock)
            ->withService(ProcessTimeZone::class, new RecordingProcessTimeZone()));
        $this->transport->deliver(TestBootstrap::createForApps(array_values($apps)));

        /** @var Future<string> $running */
        $running = async(fn(): string => $kernel->run($this->transport));
        $this->running = $running;
        $this->transport->deliver(new StateSnapshot(EntityStatesFragment::fromCollection(EntityStateCollection::keyedByEntityId([])), revision: 1));
        EventLoopTicks::settleUntil(fn(): bool => $this->transport->listSentOfType(WorkerReady::class) !== []);
    }

    private function assertWorkerStillAnswers(): void
    {
        $this->transport->deliver(new Ping(nonce: 7, sentAt: $this->timers->clock->getNow()));
        EventLoopTicks::settleUntil(fn(): bool => $this->transport->listSentOfType(Pong::class) !== []);

        self::assertSame(7, $this->transport->listSentOfType(Pong::class)[0]->nonce);
        self::assertFalse($this->running?->isComplete() ?? true, 'The worker keeps running.');
    }
}
