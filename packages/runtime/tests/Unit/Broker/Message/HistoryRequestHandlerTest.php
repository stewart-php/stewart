<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Broker\Message;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Stewart\Contracts\Action\ActionResponse;
use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\Exception\HistoryError;
use Stewart\Contracts\History\HistoryDetail;
use Stewart\Contracts\History\HistoryWindow;
use Stewart\Contracts\State\EntityState;
use Stewart\Contracts\Time\Instant;
use Stewart\Runtime\App\AppDefinition;
use Stewart\Runtime\App\Collection\AppDefinitionCollection;
use Stewart\Runtime\Broker\AppMetrics;
use Stewart\Runtime\Broker\Collection\WorkerSlotCollection;
use Stewart\Runtime\Broker\HaCallSlots;
use Stewart\Runtime\Broker\Message\HistoryRequestHandler;
use Stewart\Runtime\Broker\OutboxLimits;
use Stewart\Runtime\Broker\WorkerHandle;
use Stewart\Runtime\Broker\WorkerSlot;
use Stewart\Runtime\Config\HaCallPolicy;
use Stewart\Runtime\Ipc\Message\ActionCallRequest;
use Stewart\Runtime\Ipc\Message\ActionCallResult;
use Stewart\Runtime\Ipc\Message\HistoryRequest;
use Stewart\Runtime\Ipc\Message\HistoryResult;
use Stewart\Runtime\Ipc\Message\RequestFailed;
use Stewart\Runtime\Model\CorrelationId;
use Stewart\Runtime\Model\ResourceScope;
use Stewart\Runtime\Model\WorkerId;
use Stewart\Runtime\Tests\Fixtures\Apps\Demo;
use Stewart\Runtime\Tests\Fixtures\Broker\FakeHaSession;
use Stewart\Runtime\Tests\Fixtures\Broker\FakeHomeAssistant;
use Stewart\Runtime\Tests\Fixtures\Broker\FakeWorkerProcess;
use Stewart\Runtime\Tests\Fixtures\Ipc\FakeWorkerTransport;
use Stewart\Testing\Async\Latch;
use Stewart\Testing\Time\EventLoopTicks;
use Stewart\Testing\Time\ManualTimers;

#[CoversClass(HistoryRequestHandler::class)]
final class HistoryRequestHandlerTest extends TestCase
{
    private FakeHaSession $session;

    private FakeHomeAssistant $homeAssistant;

    private WorkerHandle $worker;

    private HaCallSlots $slots;

    protected function setUp(): void
    {
        $this->session = new FakeHaSession();
        $this->homeAssistant = FakeHomeAssistant::createConnected();
        $slot = new WorkerSlot(new WorkerId(0), AppDefinitionCollection::keyedByAppId([new AppDefinition(new AppId('demo'), Demo::class)]));
        $timers = new ManualTimers();
        $this->slots = new HaCallSlots(
            new HaCallPolicy(perWorker: 1, total: 0),
            new AppMetrics(WorkerSlotCollection::fromList([$slot]), $timers->clock),
            $timers->clock,
            new NullLogger(),
        );
        $this->worker = new WorkerHandle(
            id: new WorkerId(0),
            process: new FakeWorkerProcess(),
            slot: $slot,
            logger: new NullLogger(),
            outboxLimits: new OutboxLimits(100, 256),
        );
    }

    public function testStatesFromHomeAssistantAreSentBack(): void
    {
        $this->homeAssistant->seedHistory(new EntityState(new EntityId('light.hall'), 'off'), new EntityState(new EntityId('light.hall'), 'on'));

        $this->forwardRequest();

        $results = $this->listSentOfType(HistoryResult::class);
        self::assertCount(1, $results);
        self::assertSame('w0:7', $results[0]->correlationId->value);
        self::assertCount(2, $results[0]->states->collection);
    }

    public function testHomeAssistantFailureKeepsItsReason(): void
    {
        $this->homeAssistant->failHistory('unknown_command', 'Unknown command.');

        $this->forwardRequest();

        self::assertSame(HistoryError::RecorderUnavailable->value, $this->listSentOfType(RequestFailed::class)[0]->details->reason);
    }

    public function testDisconnectedSessionIsUnreachable(): void
    {
        $this->session->connected = false;

        $this->forwardRequest();

        self::assertSame(HistoryError::Unreachable->value, $this->listSentOfType(RequestFailed::class)[0]->details->reason);
        self::assertSame([], $this->listSentOfType(HistoryResult::class));
    }

    public function testFullCallBudgetRefusesHistory(): void
    {
        $serviceCall = new ActionCallRequest(new CorrelationId('w0:1'), ResourceScope::forApp(new AppId('demo')), 'light', 'turn_on', [], null, false);
        $held = new Latch();
        $this->slots->runBounded($this->worker, $serviceCall, static function () use ($held, $serviceCall): ActionCallResult {
            $held->waitUntilOpen();

            return new ActionCallResult($serviceCall->correlationId, new ActionResponse('light', 'turn_on'));
        });

        $this->forwardRequest();

        self::assertSame(HistoryError::Overloaded->value, $this->listSentOfType(RequestFailed::class)[0]->details->reason);
        self::assertSame(1, $this->slots->refusedCalls);
        $held->open();
        EventLoopTicks::settle();
    }

    private function forwardRequest(): void
    {
        new HistoryRequestHandler($this->session, $this->homeAssistant->client, $this->slots)->handle($this->worker, self::createRequest());
        EventLoopTicks::settle();
    }

    private static function createRequest(): HistoryRequest
    {
        return new HistoryRequest(
            new CorrelationId('w0:7'),
            ResourceScope::forApp(new AppId('demo')),
            new EntityId('light.hall'),
            new HistoryWindow(Instant::fromEpochMicroseconds(0), Instant::fromEpochMicroseconds(60_000_000)),
            HistoryDetail::StateChanges,
        );
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return list<T>
     */
    private function listSentOfType(string $class): array
    {
        $transport = $this->worker->getTransport();
        self::assertInstanceOf(FakeWorkerTransport::class, $transport);

        return $transport->listSentOfType($class);
    }
}
