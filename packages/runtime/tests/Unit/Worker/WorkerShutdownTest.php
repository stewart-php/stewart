<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Worker;

use Amp\DeferredFuture;
use Amp\Future;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\Exception\HistoryError;
use Stewart\Contracts\Exception\ServiceCallError;
use Stewart\Contracts\Time\Duration;
use Stewart\Runtime\Container\AppContainerBuilder;
use Stewart\Runtime\Container\AppOptionResolver;
use Stewart\Runtime\Ipc\WorkerApp;
use Stewart\Runtime\Model\WorkerId;
use Stewart\Runtime\Tests\Fixtures\Container\AppRuntimeServicesFixture;
use Stewart\Runtime\Tests\Fixtures\Ipc\NullTransport;
use Stewart\Runtime\Tests\Fixtures\Ipc\TestBootstrap;
use Stewart\Runtime\Tests\Fixtures\Lifecycle\GatedDisposer;
use Stewart\Runtime\Tests\Fixtures\Lifecycle\GatedInitializer;
use Stewart\Runtime\Tests\Fixtures\Worker\AppResourcesFixture;
use Stewart\Runtime\Worker\AppActivityCounters;
use Stewart\Runtime\Worker\AppFailureReporter;
use Stewart\Runtime\Worker\AppLifecycle;
use Stewart\Runtime\Worker\CorrelationIdSequence;
use Stewart\Runtime\Worker\HandlerFailureSampler;
use Stewart\Runtime\Worker\PendingRequests;
use Stewart\Runtime\Worker\StderrFallback;
use Stewart\Runtime\Worker\Subject\HistoryQuerySubject;
use Stewart\Runtime\Worker\Subject\ServiceCallSubject;
use Stewart\Runtime\Worker\WorkerShutdown;
use Stewart\Support\Text\ClosestNameFinder;
use Stewart\Testing\Exception\AssertsReason;
use Stewart\Testing\Logging\RecordedLog;
use Stewart\Testing\Logging\RecordingLogger;
use Stewart\Testing\Time\EventLoopTicks;
use Stewart\Testing\Time\ManualTimers;

use function Amp\async;

#[CoversClass(WorkerShutdown::class)]
final class WorkerShutdownTest extends TestCase
{
    use AssertsReason;

    private ManualTimers $timers;

    private RecordingLogger $logger;

    private PendingRequests $pending;

    private AppLifecycle $apps;

    private WorkerShutdown $shutdown;

    /** @var Future<mixed> */
    private Future $stopped;

    protected function setUp(): void
    {
        GatedInitializer::reset();
        GatedDisposer::reset();
        $this->timers = new ManualTimers();
        $this->logger = new RecordingLogger();
        $this->pending = new PendingRequests(new CorrelationIdSequence(new WorkerId(0)));
        $this->createShutdownFor(new WorkerApp(id: new AppId('gated'), class: GatedInitializer::class, options: []));
    }

    protected function tearDown(): void
    {
        GatedInitializer::open();
        GatedDisposer::open();
        EventLoopTicks::settle();
    }

    public function testFirstReasonToStopIsTheOneKept(): void
    {
        self::assertFalse($this->shutdown->isStopping());

        $this->shutdown->stop('broker asked', Duration::zero());
        $this->shutdown->stop('channel closed', Duration::zero());
        EventLoopTicks::settleUntil(fn(): bool => $this->stopped->isComplete());

        self::assertSame('broker asked', $this->shutdown->reason);
        self::assertTrue($this->stopped->isComplete());
    }

    public function testStoppingCancelsAStartupStillWaitingForState(): void
    {
        $cancellation = $this->shutdown->startupCancellation();

        $this->shutdown->stop('broker asked', Duration::zero());

        self::assertTrue($cancellation->isRequested());
    }

    public function testStopAwaitsStartupInProgress(): void
    {
        $startup = new DeferredFuture();
        $this->shutdown->trackStartup($startup->getFuture());

        $this->shutdown->stop('broker asked', Duration::seconds(5));
        EventLoopTicks::settle();

        self::assertFalse($this->stopped->isComplete());

        $startup->complete();
        EventLoopTicks::settleUntil(fn(): bool => $this->stopped->isComplete());

        self::assertTrue($this->stopped->isComplete());
        self::assertSame([], $this->logger->listMessagesAt('warning'));
    }

    public function testStartupThatOutlivesTheGraceIsAbandoned(): void
    {
        $this->shutdown->trackStartup(new DeferredFuture()->getFuture());

        $this->shutdown->stop('broker asked', Duration::seconds(5));
        EventLoopTicks::settle();
        $this->timers->delay(Duration::seconds(5));
        EventLoopTicks::settleUntil(fn(): bool => $this->stopped->isComplete());

        self::assertTrue($this->stopped->isComplete());
        self::assertSame(['Apps did not stop within the grace period'], $this->logger->listMessagesAt('warning'));
    }

    public function testRunningAppsDisposeWhileStartupStillRuns(): void
    {
        $this->startApps();
        $this->shutdown->trackStartup(new DeferredFuture()->getFuture());

        $this->shutdown->stop('broker asked', Duration::seconds(5));
        EventLoopTicks::settle();

        self::assertSame(['initialize start', 'initialize end', 'dispose'], GatedInitializer::$log);
        self::assertFalse($this->stopped->isComplete());

        $this->timers->delay(Duration::seconds(5));
        EventLoopTicks::settleUntil(fn(): bool => $this->stopped->isComplete());

        self::assertTrue($this->stopped->isComplete());
    }

    public function testOneDeadlineCoversStartupAndDispose(): void
    {
        $this->createShutdownFor(new WorkerApp(id: new AppId('hanging'), class: GatedDisposer::class, options: []));
        $this->startApps();
        $this->shutdown->trackStartup(new DeferredFuture()->getFuture());

        $this->shutdown->stop('broker asked', Duration::seconds(5));
        EventLoopTicks::settle();
        $this->timers->delay(Duration::seconds(5));
        EventLoopTicks::settleUntil(fn(): bool => $this->stopped->isComplete());

        self::assertTrue($this->stopped->isComplete());
        self::assertSame(['dispose start'], GatedDisposer::$log);
        self::assertSame([['hanging']], $this->listAbandonedAppsInWarnings());
    }

    public function testBrokerLossFailsPendingCallsAtOnce(): void
    {
        $waiter = $this->awaitPendingCall();

        $this->shutdown->stopAfterBrokerLoss('channel closed');

        $this->assertThrowsReason(ServiceCallError::Unreachable, static fn() => $waiter->await());
        self::assertSame('channel closed', $this->shutdown->reason);
    }

    public function testBrokerLossFailsPendingHistoryQueries(): void
    {
        $query = $this->pending->open(new HistoryQuerySubject(new EntityId('light.hall')));
        $waiter = async(static fn() => $query->getFuture()->await());
        EventLoopTicks::settle();

        $this->shutdown->stopAfterBrokerLoss('channel closed');

        $this->assertThrowsReason(HistoryError::Unreachable, static fn() => $waiter->await());
    }

    public function testBrokerLossDuringStopFailsPendingCalls(): void
    {
        $waiter = $this->awaitPendingCall();
        $this->shutdown->stop('broker asked', Duration::seconds(5));
        EventLoopTicks::settle();

        self::assertFalse($waiter->isComplete(), 'A broker that asked to stop still answers calls.');

        $this->shutdown->stopAfterBrokerLoss('channel closed');

        $this->assertThrowsReason(ServiceCallError::Unreachable, static fn() => $waiter->await());
        self::assertSame('broker asked', $this->shutdown->reason);
    }

    public function testZeroGraceStillDisposesRunningApps(): void
    {
        $this->startApps();
        $this->shutdown->trackStartup(new DeferredFuture()->getFuture());

        $this->shutdown->stop('broker lost', Duration::zero());
        EventLoopTicks::settle();
        $this->timers->delay(Duration::zero());
        EventLoopTicks::settleUntil(fn(): bool => $this->stopped->isComplete());

        self::assertTrue($this->stopped->isComplete());
        self::assertSame(['initialize start', 'initialize end', 'dispose'], GatedInitializer::$log);
    }

    public function testConfiguredGraceStopDisposesApps(): void
    {
        $this->startApps();

        $this->shutdown->stopWithConfiguredGrace('channel closed');
        EventLoopTicks::settleUntil(fn(): bool => $this->stopped->isComplete());

        self::assertTrue($this->stopped->isComplete());
        self::assertSame(['initialize start', 'initialize end', 'dispose'], GatedInitializer::$log);
    }

    private function createShutdownFor(WorkerApp $app): void
    {
        $transport = new NullTransport();
        $resources = new AppResourcesFixture($this->timers, subscriptionQueueLimit: 10);
        $this->apps = new AppLifecycle(
            TestBootstrap::createForApps([$app]),
            AppRuntimeServicesFixture::createAppRuntimeServices(transport: $transport, resources: $resources),
            $resources->resources,
            new AppFailureReporter($transport, new StderrFallback(new WorkerId(0)), new AppActivityCounters(), new HandlerFailureSampler()),
            new AppContainerBuilder(new AppOptionResolver(new ClosestNameFinder())),
        );

        $this->shutdown = new WorkerShutdown($this->apps, $resources->resources, $this->pending, $this->timers, $this->logger, Duration::seconds(5));
        $this->stopped = async($this->shutdown->awaitStopped(...));
    }

    private function startApps(): void
    {
        GatedInitializer::open();
        $this->apps->constructApps();
        $this->apps->initializeApps();
    }

    /** @return Future<mixed> */
    private function awaitPendingCall(): Future
    {
        $call = $this->pending->open(new ServiceCallSubject('light', 'turn_on'));
        $waiter = async(static fn() => $call->getFuture()->await());
        EventLoopTicks::settle();

        return $waiter;
    }

    /** @return list<mixed> */
    private function listAbandonedAppsInWarnings(): array
    {
        return $this->logger->records
            ->filter(static fn(RecordedLog $record): bool => $record->level === 'warning')
            ->mapToList(static fn(RecordedLog $record): mixed => $record->context['apps'] ?? null);
    }
}
