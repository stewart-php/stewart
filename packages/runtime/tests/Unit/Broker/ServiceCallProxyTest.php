<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Broker;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;
use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Exception\ServiceCallError;
use Stewart\Contracts\Time\Duration;
use Stewart\Runtime\App\AppDefinition;
use Stewart\Runtime\App\Collection\AppDefinitionCollection;
use Stewart\Runtime\Broker\AppMetrics;
use Stewart\Runtime\Broker\Collection\WorkerSlotCollection;
use Stewart\Runtime\Broker\OutboxLimits;
use Stewart\Runtime\Broker\ServiceCallProxy;
use Stewart\Runtime\Broker\WorkerHandle;
use Stewart\Runtime\Broker\WorkerSlot;
use Stewart\Runtime\Config\ServiceCallPolicy;
use Stewart\Runtime\Control\Assembler\AppStatusBuilder;
use Stewart\Runtime\Control\Protocol\Status\ServiceCallStats;
use Stewart\Runtime\Ipc\Message\ServiceCallFailed;
use Stewart\Runtime\Ipc\Message\ServiceCallRequest;
use Stewart\Runtime\Ipc\Message\ServiceCallResult;
use Stewart\Runtime\Model\CorrelationId;
use Stewart\Runtime\Model\ResourceScope;
use Stewart\Runtime\Model\ServiceCallOutcome;
use Stewart\Runtime\Model\WorkerId;
use Stewart\Runtime\Tests\Fixtures\Apps\Demo;
use Stewart\Runtime\Tests\Fixtures\Broker\FakeHaSession;
use Stewart\Runtime\Tests\Fixtures\Broker\FakeWorkerProcess;
use Stewart\Runtime\Tests\Fixtures\Ipc\FakeWorkerTransport;
use Stewart\Testing\Async\Latch;
use Stewart\Testing\Logging\RecordingLogger;
use Stewart\Testing\Time\EventLoopTicks;
use Stewart\Testing\Time\ManualTimers;

#[CoversClass(ServiceCallProxy::class)]
#[CoversClass(ServiceCallPolicy::class)]
final class ServiceCallProxyTest extends TestCase
{
    private FakeHaSession $session;

    private AppMetrics $metrics;

    private ManualTimers $timers;

    private Latch $calls;

    protected function setUp(): void
    {
        $this->timers = new ManualTimers();
        $this->session = new FakeHaSession();
        $this->calls = $this->session->holdCalls();
        $this->metrics = new AppMetrics(
            WorkerSlotCollection::fromWorkerSlots([new WorkerSlot(new WorkerId(0), AppDefinitionCollection::keyedByAppId([new AppDefinition(new AppId('demo'), Demo::class)]))]),
            $this->timers->clock,
        );
    }

    protected function tearDown(): void
    {
        $this->calls->open();
        EventLoopTicks::settle();
    }

    public function testCallsBeyondTheWorkerLimitAreRefusedAtOnce(): void
    {
        $proxy = new ServiceCallProxy($this->session, $this->metrics, new ServiceCallPolicy(perWorker: 2, total: 0), $this->timers->clock, new NullLogger());
        $worker = $this->createHandle(0);

        foreach (range(1, 5) as $call) {
            $proxy->forward($worker, self::createRequest((string) $call));
        }

        self::assertSame(2, $proxy->inFlight);
        self::assertSame(3, $proxy->refusedCalls);

        EventLoopTicks::settleUntil(static fn(): bool => \count(self::listSentErrors($worker)) === 3);
        $refusals = self::listSentErrors($worker);
        self::assertCount(3, $refusals);
        self::assertSame(ServiceCallError::Overloaded, $refusals[0]->getReason());

        $this->finishCalls();

        self::assertSame(0, $proxy->inFlight);
        self::assertCount(2, self::listSentResultsFor($worker));
        self::assertSame(2, $this->session->calls, 'A refused call never reaches Home Assistant.');
    }

    public function testCountsCallsPerAppByOutcome(): void
    {
        $proxy = new ServiceCallProxy($this->session, $this->metrics, new ServiceCallPolicy(perWorker: 1, total: 0), $this->timers->clock, new NullLogger());
        $worker = $this->createHandle(0);

        $proxy->forward($worker, self::createRequest('a'));
        $proxy->forward($worker, self::createRequest('b'));
        EventLoopTicks::settle();
        $this->timers->delay(Duration::milliseconds(30));
        $this->finishCalls();

        $calls = new AppStatusBuilder($this->metrics)->buildAppStatuses()->listValues()[0]->serviceCalls;
        self::assertSame(
            [ServiceCallOutcome::Refused, ServiceCallOutcome::Succeeded],
            array_map(static fn(ServiceCallStats $stats): ServiceCallOutcome => $stats->outcome, $calls),
        );
        self::assertSame(1, $calls[0]->count);
        self::assertNull($calls[0]->latency, 'A refused call never waited on Home Assistant.');
        self::assertSame(1, $calls[1]->latency?->count);
        self::assertSame(30, $calls[1]->latency->sum->toMilliseconds());
        self::assertSame(0, $calls[1]->latency->cumulativeCounts[2], 'A 30 ms call is slower than the 25 ms bucket.');
        self::assertSame(1, $calls[1]->latency->cumulativeCounts[3]);
    }

    public function testServesOtherWorkersWhileOneIsAtLimit(): void
    {
        $proxy = new ServiceCallProxy($this->session, $this->metrics, new ServiceCallPolicy(perWorker: 1, total: 0), $this->timers->clock, new NullLogger());
        $busy = $this->createHandle(0);
        $other = $this->createHandle(1);

        $proxy->forward($busy, self::createRequest('busy-1'));
        $proxy->forward($busy, self::createRequest('busy-2'));
        $proxy->forward($other, self::createRequest('other-1'));

        EventLoopTicks::settleUntil(static fn(): bool => \count(self::listSentErrors($busy)) === 1);

        self::assertCount(1, self::listSentErrors($busy));
        self::assertCount(0, self::listSentErrors($other), 'One noisy worker must not starve the others.');

        $this->finishCalls();

        self::assertCount(1, self::listSentResultsFor($other));
    }

    public function testGlobalLimitBoundsEveryWorkerTogether(): void
    {
        $proxy = new ServiceCallProxy($this->session, $this->metrics, new ServiceCallPolicy(perWorker: 0, total: 2), $this->timers->clock, new NullLogger());
        $first = $this->createHandle(0);
        $second = $this->createHandle(1);

        $proxy->forward($first, self::createRequest('a'));
        $proxy->forward($second, self::createRequest('b'));
        $proxy->forward($second, self::createRequest('c'));

        self::assertSame(2, $proxy->inFlight);

        EventLoopTicks::settleUntil(static fn(): bool => \count(self::listSentErrors($second)) === 1);

        self::assertCount(1, self::listSentErrors($second));

        $this->finishCalls();

        self::assertSame(0, $proxy->inFlight);
    }

    public function testSlotsAreFreedOnceCallsFinish(): void
    {
        $proxy = new ServiceCallProxy($this->session, $this->metrics, new ServiceCallPolicy(perWorker: 1, total: 1), $this->timers->clock, new NullLogger());
        $worker = $this->createHandle(0);

        $proxy->forward($worker, self::createRequest('first'));
        $this->finishCalls();

        $proxy->forward($worker, self::createRequest('second'));
        $this->finishCalls();

        self::assertSame(0, $proxy->refusedCalls);
        self::assertCount(2, self::listSentResultsFor($worker));
    }

    public function testReplacementStartsWithoutPredecessorCalls(): void
    {
        $proxy = new ServiceCallProxy($this->session, $this->metrics, new ServiceCallPolicy(perWorker: 1, total: 0), $this->timers->clock, new NullLogger());
        $dead = $this->createHandle(0);
        $replacement = $this->createHandle(0);

        $proxy->forward($dead, self::createRequest('first'));
        $proxy->forward($replacement, self::createRequest('second'));

        self::assertSame(0, $proxy->refusedCalls);
        self::assertSame(1, $proxy->countInFlightCallsFor($replacement));
    }

    public function testStaleReleaseKeepsReplacementCount(): void
    {
        $proxy = new ServiceCallProxy($this->session, $this->metrics, new ServiceCallPolicy(perWorker: 2, total: 0), $this->timers->clock, new NullLogger());
        $dead = $this->createHandle(0);
        $replacement = $this->createHandle(0);

        $proxy->forward($dead, self::createRequest('stale'));
        EventLoopTicks::settle();
        $stale = $this->calls;
        $this->calls = $this->session->holdCalls();
        $proxy->forward($replacement, self::createRequest('fresh'));
        EventLoopTicks::settle();

        $stale->open();
        EventLoopTicks::settleUntil(static fn(): bool => $proxy->countInFlightCallsFor($dead) === 0);

        self::assertSame(0, $proxy->countInFlightCallsFor($dead), 'The dead worker\'s call has finished.');
        self::assertSame(1, $proxy->countInFlightCallsFor($replacement));
        self::assertSame(1, $proxy->inFlight);

        $this->finishCalls();

        self::assertSame(0, $proxy->countInFlightCallsFor($replacement));
    }

    public function testDryRunAnswersWithoutReachingHomeAssistant(): void
    {
        $logger = new RecordingLogger();
        $proxy = new ServiceCallProxy($this->session, $this->metrics, new ServiceCallPolicy(perWorker: 1, total: 0, dryRun: true), $this->timers->clock, $logger);
        $worker = $this->createHandle(0);

        $proxy->forward($worker, self::createRequest('dry'));
        EventLoopTicks::settleUntil(static fn(): bool => self::listSentResultsFor($worker) !== []);

        self::assertSame('turn_on', self::listSentResultsFor($worker)[0]->result->service);
        self::assertStringStartsWith('dry-run:', (string) self::listSentResultsFor($worker)[0]->result->context?->id);
        self::assertSame(0, $this->session->calls);
        self::assertSame(0, $proxy->inFlight);
        self::assertSame(['Service call not sent: service_calls.dry_run is on'], $logger->listMessagesAt(LogLevel::INFO));
    }

    private function finishCalls(): void
    {
        $this->calls->open();
        EventLoopTicks::settle();
        $this->calls = $this->session->holdCalls();
    }

    private function createHandle(int $workerId): WorkerHandle
    {
        return new WorkerHandle(
            id: new WorkerId($workerId),
            process: new FakeWorkerProcess(),
            slot: new WorkerSlot(new WorkerId($workerId), AppDefinitionCollection::keyedByAppId([new AppDefinition(new AppId('demo'), Demo::class)])),
            logger: new NullLogger(),
            outboxLimits: new OutboxLimits(100, 256),
        );
    }

    private static function createRequest(string $correlationId): ServiceCallRequest
    {
        return new ServiceCallRequest(
            correlationId: new CorrelationId($correlationId),
            scope: ResourceScope::forApp(new AppId('demo')),
            domain: 'light',
            service: 'turn_on',
            data: [],
            target: null,
            returnResponse: false,
        );
    }

    /** @return list<ServiceCallFailed> */
    private static function listSentErrors(WorkerHandle $handle): array
    {
        return self::listSentOfType($handle, ServiceCallFailed::class);
    }

    /** @return list<ServiceCallResult> */
    private static function listSentResultsFor(WorkerHandle $handle): array
    {
        return self::listSentOfType($handle, ServiceCallResult::class);
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return list<T>
     */
    private static function listSentOfType(WorkerHandle $handle, string $class): array
    {
        $transport = $handle->getTransport();
        self::assertInstanceOf(FakeWorkerTransport::class, $transport);

        return $transport->listSentOfType($class);
    }
}
