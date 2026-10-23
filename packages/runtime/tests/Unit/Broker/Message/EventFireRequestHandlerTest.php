<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Broker\Message;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel as PsrLogLevel;
use Psr\Log\NullLogger;
use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Exception\EventFireError;
use Stewart\Runtime\App\AppDefinition;
use Stewart\Runtime\App\Collection\AppDefinitionCollection;
use Stewart\Runtime\Broker\ActionCallTracer;
use Stewart\Runtime\Broker\AppMetrics;
use Stewart\Runtime\Broker\AppPauseRegistry;
use Stewart\Runtime\Broker\Collection\WorkerSlotCollection;
use Stewart\Runtime\Broker\DaemonStartTime;
use Stewart\Runtime\Broker\HaCallSlots;
use Stewart\Runtime\Broker\Message\ActionCallRequestHandler;
use Stewart\Runtime\Broker\Message\EventFireRequestHandler;
use Stewart\Runtime\Broker\OutboxLimits;
use Stewart\Runtime\Broker\WorkerHandle;
use Stewart\Runtime\Broker\WorkerSlot;
use Stewart\Runtime\Config\HaCallPolicy;
use Stewart\Runtime\Control\Assembler\AppStatusBuilder;
use Stewart\Runtime\Control\Protocol\Status\HaCallStats;
use Stewart\Runtime\Ipc\Message\ActionCallRequest;
use Stewart\Runtime\Ipc\Message\EventFireRequest;
use Stewart\Runtime\Ipc\Message\EventFireResult;
use Stewart\Runtime\Ipc\Message\RequestFailed;
use Stewart\Runtime\Logging\LogVerbosityPolicy;
use Stewart\Runtime\Model\CorrelationId;
use Stewart\Runtime\Model\HaCallOutcome;
use Stewart\Runtime\Model\LogLevel;
use Stewart\Runtime\Model\ResourceScope;
use Stewart\Runtime\Model\WorkerId;
use Stewart\Runtime\Tests\Fixtures\Apps\Demo;
use Stewart\Runtime\Tests\Fixtures\Broker\FakeHaSession;
use Stewart\Runtime\Tests\Fixtures\Broker\FakeHomeAssistant;
use Stewart\Runtime\Tests\Fixtures\Broker\FakeWorkerProcess;
use Stewart\Runtime\Tests\Fixtures\Broker\ReceivedEventFire;
use Stewart\Runtime\Tests\Fixtures\Ipc\FakeWorkerTransport;
use Stewart\Testing\Async\Latch;
use Stewart\Testing\Logging\RecordingLogger;
use Stewart\Testing\Time\EventLoopTicks;
use Stewart\Testing\Time\ManualTimers;
use Stewart\Testing\Time\VirtualClock;

#[CoversClass(EventFireRequestHandler::class)]
#[CoversClass(HaCallOutcome::class)]
final class EventFireRequestHandlerTest extends TestCase
{
    private FakeHaSession $session;

    private FakeHomeAssistant $homeAssistant;

    private AppMetrics $metrics;

    private ManualTimers $timers;

    private Latch $calls;

    private HaCallSlots $slots;

    protected function setUp(): void
    {
        $this->timers = new ManualTimers();
        $this->session = new FakeHaSession();
        $this->homeAssistant = FakeHomeAssistant::createConnected();
        $this->calls = $this->homeAssistant->holdReplies();
        $this->metrics = new AppMetrics(
            WorkerSlotCollection::fromList([new WorkerSlot(new WorkerId(0), AppDefinitionCollection::keyedByAppId([new AppDefinition(new AppId('demo'), Demo::class)]))]),
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
        $handler = $this->createHandler(new HaCallPolicy(perWorker: 1, total: 0));
        $worker = $this->createHandle();

        $handler->handle($worker, self::createRequest('a'));
        $this->finishCalls();

        $results = self::listSentOfType($worker, EventFireResult::class);
        self::assertCount(1, $results);
        self::assertSame(FakeHomeAssistant::FIRE_CONTEXT_PREFIX . '1', $results[0]->context->id);
        self::assertEquals([new ReceivedEventFire('doorbell_pressed', ['button' => 'front'])], $this->homeAssistant->listReceivedEventFires());
        self::assertSame(0, $this->slots->inFlight);
    }

    public function testServiceCallsAndFiresShareOneBudget(): void
    {
        $policy = new HaCallPolicy(perWorker: 1, total: 0);
        $handler = $this->createHandler($policy);
        $actionCalls = new ActionCallRequestHandler($this->session, $this->homeAssistant->client, $this->slots, $policy, new ActionCallTracer(new NullLogger(), new LogVerbosityPolicy(LogLevel::Info)), new VirtualClock(), new NullLogger());
        $worker = $this->createHandle();

        $actionCalls->handle($worker, new ActionCallRequest(new CorrelationId('call'), ResourceScope::forApp(new AppId('demo')), 'light', 'turn_on', [], null, false));
        $handler->handle($worker, self::createRequest('fire'));
        EventLoopTicks::settleUntil(static fn(): bool => self::listSentOfType($worker, RequestFailed::class) !== []);

        $refusals = self::listSentOfType($worker, RequestFailed::class);
        self::assertCount(1, $refusals);
        self::assertSame(EventFireError::Overloaded->value, $refusals[0]->details->reason);
        self::assertSame(1, $this->slots->refusedCalls);
        self::assertSame([], $this->homeAssistant->listReceivedEventFires());
    }

    public function testDisconnectedFireFailsAsUnreachable(): void
    {
        $handler = $this->createHandler(new HaCallPolicy(perWorker: 1, total: 0));
        $worker = $this->createHandle();
        $this->session->connected = false;

        $handler->handle($worker, self::createRequest('a'));
        EventLoopTicks::settleUntil(static fn(): bool => self::listSentOfType($worker, RequestFailed::class) !== []);

        self::assertSame(EventFireError::Unreachable->value, self::listSentOfType($worker, RequestFailed::class)[0]->details->reason);
    }

    public function testFiresAreCountedAsCallsPerApp(): void
    {
        $handler = $this->createHandler(new HaCallPolicy(perWorker: 1, total: 0));
        $worker = $this->createHandle();

        $handler->handle($worker, self::createRequest('a'));
        $handler->handle($worker, self::createRequest('b'));
        $this->finishCalls();

        $calls = new AppStatusBuilder($this->metrics, new AppPauseRegistry(AppDefinitionCollection::keyedByAppId([]), new DaemonStartTime(new VirtualClock())), FakeHomeAssistant::createUnreachable()->exposures)->buildAppStatuses()[0]->haCalls;
        self::assertSame(
            [HaCallOutcome::Refused, HaCallOutcome::Succeeded],
            array_map(static fn(HaCallStats $stats): HaCallOutcome => $stats->outcome, $calls),
        );
    }

    public function testDryRunAnswersWithoutReachingHomeAssistant(): void
    {
        $logger = new RecordingLogger();
        $handler = $this->createHandler(new HaCallPolicy(perWorker: 1, total: 0, dryRun: true), $logger);
        $worker = $this->createHandle();

        $handler->handle($worker, self::createRequest('dry'));
        EventLoopTicks::settleUntil(static fn(): bool => self::listSentOfType($worker, EventFireResult::class) !== []);

        self::assertSame('dry-run:dry', self::listSentOfType($worker, EventFireResult::class)[0]->context->id);
        self::assertSame([], $this->homeAssistant->listReceivedEventFires());
        self::assertSame(['Event not fired: ha_calls.dry_run is on'], $logger->listMessagesAt(PsrLogLevel::INFO));
    }

    private function createHandler(HaCallPolicy $policy, ?RecordingLogger $logger = null): EventFireRequestHandler
    {
        $this->slots = new HaCallSlots($policy, $this->metrics, $this->timers->clock, new NullLogger());

        return new EventFireRequestHandler($this->session, $this->homeAssistant->client, $this->slots, $policy, $logger ?? new NullLogger());
    }

    private function finishCalls(): void
    {
        $this->calls->open();
        EventLoopTicks::settle();
        $this->calls = $this->homeAssistant->holdReplies();
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
