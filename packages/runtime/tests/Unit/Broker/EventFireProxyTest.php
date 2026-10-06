<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Broker;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;
use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Exception\EventFireError;
use Stewart\Runtime\App\AppDefinition;
use Stewart\Runtime\App\Collection\AppDefinitionCollection;
use Stewart\Runtime\Broker\AppMetrics;
use Stewart\Runtime\Broker\AppPauseRegistry;
use Stewart\Runtime\Broker\Collection\WorkerSlotCollection;
use Stewart\Runtime\Broker\EventFireProxy;
use Stewart\Runtime\Broker\HaCallSlots;
use Stewart\Runtime\Broker\OutboxLimits;
use Stewart\Runtime\Broker\ServiceCallProxy;
use Stewart\Runtime\Broker\WorkerHandle;
use Stewart\Runtime\Broker\WorkerSlot;
use Stewart\Runtime\Config\ServiceCallPolicy;
use Stewart\Runtime\Control\Assembler\AppStatusBuilder;
use Stewart\Runtime\Control\Protocol\Status\ServiceCallStats;
use Stewart\Runtime\Ipc\Message\EventFireFailed;
use Stewart\Runtime\Ipc\Message\EventFireRequest;
use Stewart\Runtime\Ipc\Message\EventFireResult;
use Stewart\Runtime\Ipc\Message\ServiceCallRequest;
use Stewart\Runtime\Model\CorrelationId;
use Stewart\Runtime\Model\ResourceScope;
use Stewart\Runtime\Model\ServiceCallOutcome;
use Stewart\Runtime\Model\WorkerId;
use Stewart\Runtime\Tests\Fixtures\Apps\Demo;
use Stewart\Runtime\Tests\Fixtures\Broker\FakeHaSession;
use Stewart\Runtime\Tests\Fixtures\Broker\FakeWorkerProcess;
use Stewart\Runtime\Tests\Fixtures\Broker\ReceivedEventFire;
use Stewart\Runtime\Tests\Fixtures\Ipc\FakeWorkerTransport;
use Stewart\Testing\Async\Latch;
use Stewart\Testing\Logging\RecordingLogger;
use Stewart\Testing\Time\EventLoopTicks;
use Stewart\Testing\Time\ManualTimers;
use Stewart\Testing\Time\VirtualClock;

#[CoversClass(EventFireProxy::class)]
#[CoversClass(ServiceCallOutcome::class)]
final class EventFireProxyTest extends TestCase
{
    private FakeHaSession $session;

    private AppMetrics $metrics;

    private ManualTimers $timers;

    private Latch $calls;

    private HaCallSlots $slots;

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

    public function testFiredEventAnswersWithHaContext(): void
    {
        $proxy = $this->createProxy(new ServiceCallPolicy(perWorker: 1, total: 0));
        $worker = $this->createHandle();

        $proxy->forward($worker, self::createRequest('a'));
        $this->finishCalls();

        $results = self::listSentOfType($worker, EventFireResult::class);
        self::assertCount(1, $results);
        self::assertSame(FakeHaSession::FIRE_CONTEXT_PREFIX . '1', $results[0]->context->id);
        self::assertEquals([new ReceivedEventFire('doorbell_pressed', ['button' => 'front'])], $this->session->receivedEventFires);
        self::assertSame(0, $this->slots->inFlight);
    }

    public function testServiceCallsAndFiresShareOneBudget(): void
    {
        $policy = new ServiceCallPolicy(perWorker: 1, total: 0);
        $proxy = $this->createProxy($policy);
        $serviceCalls = new ServiceCallProxy($this->session, $this->slots, $this->metrics, $policy, $this->timers->clock, new NullLogger());
        $worker = $this->createHandle();

        $serviceCalls->forward($worker, new ServiceCallRequest(new CorrelationId('call'), ResourceScope::forApp(new AppId('demo')), 'light', 'turn_on', [], null, false));
        $proxy->forward($worker, self::createRequest('fire'));
        EventLoopTicks::settleUntil(static fn(): bool => self::listSentOfType($worker, EventFireFailed::class) !== []);

        $refusals = self::listSentOfType($worker, EventFireFailed::class);
        self::assertCount(1, $refusals);
        self::assertSame(EventFireError::Overloaded, $refusals[0]->getReason());
        self::assertSame(1, $this->slots->refusedCalls);
        self::assertSame([], $this->session->receivedEventFires);
    }

    public function testDisconnectedFireFailsAsUnreachable(): void
    {
        $proxy = $this->createProxy(new ServiceCallPolicy(perWorker: 1, total: 0));
        $worker = $this->createHandle();
        $this->session->connected = false;

        $proxy->forward($worker, self::createRequest('a'));
        EventLoopTicks::settleUntil(static fn(): bool => self::listSentOfType($worker, EventFireFailed::class) !== []);

        self::assertSame(EventFireError::Unreachable, self::listSentOfType($worker, EventFireFailed::class)[0]->getReason());
    }

    public function testFiresAreCountedAsCallsPerApp(): void
    {
        $proxy = $this->createProxy(new ServiceCallPolicy(perWorker: 1, total: 0));
        $worker = $this->createHandle();

        $proxy->forward($worker, self::createRequest('a'));
        $proxy->forward($worker, self::createRequest('b'));
        $this->finishCalls();

        $calls = new AppStatusBuilder($this->metrics, new AppPauseRegistry(AppDefinitionCollection::keyedByAppId([]), new VirtualClock()))->buildAppStatuses()->listValues()[0]->serviceCalls;
        self::assertSame(
            [ServiceCallOutcome::Refused, ServiceCallOutcome::Succeeded],
            array_map(static fn(ServiceCallStats $stats): ServiceCallOutcome => $stats->outcome, $calls),
        );
    }

    public function testDryRunAnswersWithoutReachingHomeAssistant(): void
    {
        $logger = new RecordingLogger();
        $proxy = $this->createProxy(new ServiceCallPolicy(perWorker: 1, total: 0, dryRun: true), $logger);
        $worker = $this->createHandle();

        $proxy->forward($worker, self::createRequest('dry'));
        EventLoopTicks::settleUntil(static fn(): bool => self::listSentOfType($worker, EventFireResult::class) !== []);

        self::assertSame('dry-run:dry', self::listSentOfType($worker, EventFireResult::class)[0]->context->id);
        self::assertSame([], $this->session->receivedEventFires);
        self::assertSame(['Event not fired: service_calls.dry_run is on'], $logger->listMessagesAt(LogLevel::INFO));
    }

    private function createProxy(ServiceCallPolicy $policy, ?RecordingLogger $logger = null): EventFireProxy
    {
        $this->slots = new HaCallSlots($policy, new NullLogger());

        return new EventFireProxy($this->session, $this->slots, $this->metrics, $policy, $this->timers->clock, $logger ?? new NullLogger());
    }

    private function finishCalls(): void
    {
        $this->calls->open();
        EventLoopTicks::settle();
        $this->calls = $this->session->holdCalls();
    }

    private function createHandle(): WorkerHandle
    {
        return new WorkerHandle(
            id: new WorkerId(0),
            process: new FakeWorkerProcess(),
            slot: new WorkerSlot(new WorkerId(0), AppDefinitionCollection::keyedByAppId([new AppDefinition(new AppId('demo'), Demo::class)])),
            logger: new NullLogger(),
            outboxLimits: new OutboxLimits(100, 256),
        );
    }

    private static function createRequest(string $correlationId): EventFireRequest
    {
        return new EventFireRequest(new CorrelationId($correlationId), ResourceScope::forApp(new AppId('demo')), 'doorbell_pressed', ['button' => 'front']);
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
