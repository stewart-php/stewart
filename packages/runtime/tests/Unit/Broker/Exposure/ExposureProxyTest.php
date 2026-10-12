<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Broker\Exposure;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;
use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\Exception\ExposureError;
use Stewart\Contracts\Exception\ExposureException;
use Stewart\Contracts\Exposure\ExposedEntityKey;
use Stewart\Contracts\Exposure\ExposedEntitySnapshot;
use Stewart\Contracts\Exposure\ExposedState;
use Stewart\Contracts\Exposure\ExposedStateChange;
use Stewart\Contracts\Exposure\SensorConfig;
use Stewart\Contracts\Service\ServiceResponse;
use Stewart\Runtime\App\AppDefinition;
use Stewart\Runtime\App\Collection\AppDefinitionCollection;
use Stewart\Runtime\Broker\AppMetrics;
use Stewart\Runtime\Broker\Collection\WorkerSlotCollection;
use Stewart\Runtime\Broker\Exposure\ExposureProxy;
use Stewart\Runtime\Broker\HaCallSlots;
use Stewart\Runtime\Broker\OutboxLimits;
use Stewart\Runtime\Broker\WorkerHandle;
use Stewart\Runtime\Broker\WorkerSlot;
use Stewart\Runtime\Config\ServiceCallPolicy;
use Stewart\Runtime\Ipc\Message\ExposeEntityRequest;
use Stewart\Runtime\Ipc\Message\ExposeEntityResult;
use Stewart\Runtime\Ipc\Message\ExposureAcknowledged;
use Stewart\Runtime\Ipc\Message\ReconfigureExposedEntityRequest;
use Stewart\Runtime\Ipc\Message\RequestFailed;
use Stewart\Runtime\Ipc\Message\ServiceCallRequest;
use Stewart\Runtime\Ipc\Message\ServiceCallResult;
use Stewart\Runtime\Ipc\Message\UpdateExposedEntityRequest;
use Stewart\Runtime\Model\CorrelationId;
use Stewart\Runtime\Model\ResourceScope;
use Stewart\Runtime\Model\WorkerId;
use Stewart\Runtime\Tests\Fixtures\Apps\Demo;
use Stewart\Runtime\Tests\Fixtures\Broker\FakeHaSession;
use Stewart\Runtime\Tests\Fixtures\Broker\FakeWorkerProcess;
use Stewart\Runtime\Tests\Fixtures\Ipc\FakeWorkerTransport;
use Stewart\Testing\Async\Latch;
use Stewart\Testing\Time\EventLoopTicks;
use Stewart\Testing\Time\ManualTimers;

#[CoversClass(ExposureProxy::class)]
final class ExposureProxyTest extends TestCase
{
    private FakeHaSession $session;

    private WorkerHandle $worker;

    private HaCallSlots $slots;

    protected function setUp(): void
    {
        $this->session = new FakeHaSession();
        $slot = new WorkerSlot(new WorkerId(0), AppDefinitionCollection::keyedByAppId([new AppDefinition(new AppId('demo'), Demo::class)]));
        $timers = new ManualTimers();
        $this->slots = new HaCallSlots(
            new ServiceCallPolicy(perWorker: 1, total: 0),
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

    public function testSnapshotIsSentBack(): void
    {
        $this->session->exposedSnapshot = new ExposedEntitySnapshot(new EntityId('sensor.demo_level'), new ExposedState(3), [], true);

        $this->forwardExpose(ResourceScope::forApp(new AppId('demo')));

        self::assertSame('sensor.demo_level', $this->listSentOfType(ExposeEntityResult::class)[0]->snapshot?->entityId->value);
    }

    public function testRefusalKeepsItsReason(): void
    {
        $this->session->exposureFailure = ExposureException::createForReason(ExposureError::ComponentMissing, key: 'level');

        $this->forwardExpose(ResourceScope::forApp(new AppId('demo')));

        self::assertSame(ExposureError::ComponentMissing->value, $this->listSentOfType(RequestFailed::class)[0]->details->reason);
    }

    public function testSharedScopeIsOutsideApp(): void
    {
        $this->forwardExpose(ResourceScope::shared());

        self::assertSame(ExposureError::OutsideApp->value, $this->listSentOfType(RequestFailed::class)[0]->details->reason);
        self::assertSame([], $this->session->exposedEntities);
    }

    public function testUpdateIsAcknowledged(): void
    {
        $this->forwardExpose(ResourceScope::forApp(new AppId('demo')));

        new ExposureProxy($this->session, $this->slots)->forwardUpdate(
            $this->worker,
            new UpdateExposedEntityRequest(new CorrelationId('w0:2'), ResourceScope::forApp(new AppId('demo')), new ExposedEntityKey('level'), new ExposedStateChange(new ExposedState(4))),
        );
        EventLoopTicks::settle();

        self::assertSame('w0:2', $this->listSentOfType(ExposureAcknowledged::class)[0]->correlationId->value);
        self::assertSame(4, $this->session->exposedEntities['demo/level']->state?->value);
    }

    public function testReconfigureAnswersWithSnapshot(): void
    {
        $this->session->exposedSnapshot = new ExposedEntitySnapshot(new EntityId('sensor.demo_level'), new ExposedState(3), [], true);

        new ExposureProxy($this->session, $this->slots)->forwardReconfigure(
            $this->worker,
            new ReconfigureExposedEntityRequest(new CorrelationId('w0:3'), ResourceScope::forApp(new AppId('demo')), new ExposedEntityKey('level'), new SensorConfig(unit: '%')),
        );
        EventLoopTicks::settle();

        self::assertSame('w0:3', $this->listSentOfType(ExposeEntityResult::class)[0]->correlationId->value);
        self::assertSame(['unit_of_measurement' => '%'], $this->session->reconfiguredDefinitions['demo/level']->config);
    }

    public function testUnexpectedFailureAnswersUnreachable(): void
    {
        $this->session->exposureFailure = new RuntimeException('surprise');

        $this->forwardExpose(ResourceScope::forApp(new AppId('demo')));

        self::assertSame(ExposureError::Unreachable->value, $this->listSentOfType(RequestFailed::class)[0]->details->reason);
        self::assertSame(0, $this->slots->inFlight);
    }

    public function testExposureIsNotRefusedWhenTheCallBudgetIsFull(): void
    {
        $serviceCall = new ServiceCallRequest(new CorrelationId('w0:9'), ResourceScope::forApp(new AppId('demo')), 'light', 'turn_on', [], null, false);
        $held = new Latch();
        $this->slots->runBounded($this->worker, $serviceCall, static function () use ($held, $serviceCall): ServiceCallResult {
            $held->waitUntilOpen();

            return new ServiceCallResult($serviceCall->correlationId, new ServiceResponse('light', 'turn_on'));
        });

        $this->forwardExpose(ResourceScope::forApp(new AppId('demo')));

        self::assertCount(1, $this->listSentOfType(ExposeEntityResult::class));
        self::assertSame(0, $this->slots->refusedCalls);
        $held->open();
        EventLoopTicks::settle();
    }

    private function forwardExpose(ResourceScope $scope): void
    {
        new ExposureProxy($this->session, $this->slots)->forwardExpose(
            $this->worker,
            new ExposeEntityRequest(new CorrelationId('w0:1'), $scope, new ExposedEntityKey('level'), new SensorConfig(), null, new ExposedStateChange(new ExposedState(3))),
        );
        EventLoopTicks::settle();
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
