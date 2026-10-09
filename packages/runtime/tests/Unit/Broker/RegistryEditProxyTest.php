<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Broker;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;
use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\Exception\RegistryEditError;
use Stewart\Contracts\Registry\EntityAlias;
use Stewart\Contracts\Registry\LabelId;
use Stewart\Contracts\Registry\RegisteredEntity;
use Stewart\Contracts\Registry\Update\EntityRegistryUpdate;
use Stewart\Runtime\App\AppDefinition;
use Stewart\Runtime\App\Collection\AppDefinitionCollection;
use Stewart\Runtime\Broker\AppMetrics;
use Stewart\Runtime\Broker\Collection\WorkerSlotCollection;
use Stewart\Runtime\Broker\HaCallSlots;
use Stewart\Runtime\Broker\OutboxLimits;
use Stewart\Runtime\Broker\RegistryEditProxy;
use Stewart\Runtime\Broker\WorkerHandle;
use Stewart\Runtime\Broker\WorkerSlot;
use Stewart\Runtime\Config\ServiceCallPolicy;
use Stewart\Runtime\Ipc\Message\RegistryEntityUpdateFailed;
use Stewart\Runtime\Ipc\Message\RegistryEntityUpdateRequest;
use Stewart\Runtime\Ipc\Message\RegistryEntityUpdateResult;
use Stewart\Runtime\Model\CorrelationId;
use Stewart\Runtime\Model\ResourceScope;
use Stewart\Runtime\Model\ServiceCallOutcome;
use Stewart\Runtime\Model\WorkerId;
use Stewart\Runtime\Tests\Fixtures\Apps\Demo;
use Stewart\Runtime\Tests\Fixtures\Broker\FakeHaSession;
use Stewart\Runtime\Tests\Fixtures\Broker\FakeWorkerProcess;
use Stewart\Runtime\Tests\Fixtures\Ipc\FakeWorkerTransport;
use Stewart\Testing\Logging\RecordingLogger;
use Stewart\Testing\Time\EventLoopTicks;
use Stewart\Testing\Time\ManualTimers;

#[CoversClass(RegistryEditProxy::class)]
#[CoversClass(ServiceCallOutcome::class)]
final class RegistryEditProxyTest extends TestCase
{
    private FakeHaSession $session;

    private AppMetrics $metrics;

    private ManualTimers $timers;

    private HaCallSlots $slots;

    protected function setUp(): void
    {
        $this->timers = new ManualTimers();
        $this->session = new FakeHaSession();
        $this->session->seedRegistryEntry(new RegisteredEntity(
            new EntityId('light.hall'),
            labelIds: [new LabelId('night'), new LabelId('hue')],
            name: 'Hall',
            aliases: [EntityAlias::named('Hall lamp')],
        ));
        $this->metrics = new AppMetrics(
            WorkerSlotCollection::fromWorkerSlots([new WorkerSlot(new WorkerId(0), AppDefinitionCollection::keyedByAppId([new AppDefinition(new AppId('demo'), Demo::class)]))]),
            $this->timers->clock,
        );
    }

    public function testUpdateAnswersWithUpdatedEntry(): void
    {
        $worker = $this->createHandle();

        $this->createProxy(new ServiceCallPolicy(perWorker: 0, total: 0))->forward($worker, self::createRequest(new EntityRegistryUpdate()->withName('Hallway')));

        self::assertSame('Hallway', $this->awaitResult($worker)->entity->name);
        self::assertSame(0, $this->slots->inFlight);
    }

    public function testListEditsMergeWithCurrentEntry(): void
    {
        $worker = $this->createHandle();
        $update = new EntityRegistryUpdate()->withAddedLabels('battery')->withRemovedLabels('hue')->withAddedAliases(EntityAlias::entityName());

        $this->createProxy(new ServiceCallPolicy(perWorker: 0, total: 0))->forward($worker, self::createRequest($update));

        $entity = $this->awaitResult($worker)->entity;
        self::assertSame(['night', 'battery'], $entity->listLabelIds()->toStrings());
        self::assertEquals([EntityAlias::named('Hall lamp'), EntityAlias::entityName()], $entity->aliases);
        self::assertFalse($this->session->sentRegistryUpdates[0]->needsCurrentEntry());
    }

    public function testUnknownEntityFailsAsNotFound(): void
    {
        $worker = $this->createHandle();

        $this->createProxy(new ServiceCallPolicy(perWorker: 0, total: 0))->forward($worker, self::createRequest(new EntityRegistryUpdate()->withHidden(true), 'light.gone'));

        self::assertSame(RegistryEditError::NotFound, $this->awaitFailure($worker)->getReason());
    }

    public function testDisconnectedUpdateFailsAsUnreachable(): void
    {
        $worker = $this->createHandle();
        $this->session->connected = false;

        $this->createProxy(new ServiceCallPolicy(perWorker: 0, total: 0))->forward($worker, self::createRequest(new EntityRegistryUpdate()->withName('Hallway')));

        self::assertSame(RegistryEditError::Unreachable, $this->awaitFailure($worker)->getReason());
    }

    public function testFullCallBudgetRefusesUpdate(): void
    {
        $worker = $this->createHandle();
        $proxy = $this->createProxy(new ServiceCallPolicy(perWorker: 1, total: 0));
        $this->slots->acquire($worker);

        $proxy->forward($worker, self::createRequest(new EntityRegistryUpdate()->withName('Hallway')));

        self::assertSame(RegistryEditError::Overloaded, $this->awaitFailure($worker)->getReason());
        self::assertSame([], $this->session->sentRegistryUpdates);
    }

    public function testDryRunPreviewsWithoutUpdating(): void
    {
        $logger = new RecordingLogger();
        $worker = $this->createHandle();

        $this->createProxy(new ServiceCallPolicy(perWorker: 0, total: 0, dryRun: true), $logger)->forward($worker, self::createRequest(new EntityRegistryUpdate()->withHidden(true)));

        self::assertTrue($this->awaitResult($worker)->entity->isHidden());
        self::assertSame([], $this->session->sentRegistryUpdates);
        self::assertSame(['Registry entry not updated: service_calls.dry_run is on'], $logger->listMessagesAt(LogLevel::INFO));
    }

    private function createProxy(ServiceCallPolicy $policy, ?RecordingLogger $logger = null): RegistryEditProxy
    {
        $this->slots = new HaCallSlots($policy, new NullLogger());

        return new RegistryEditProxy($this->session, $this->slots, $this->metrics, $policy, $this->timers->clock, $logger ?? new NullLogger());
    }

    private function awaitResult(WorkerHandle $worker): RegistryEntityUpdateResult
    {
        EventLoopTicks::settleUntil(static fn(): bool => self::listSentOfType($worker, RegistryEntityUpdateResult::class) !== []);

        return self::listSentOfType($worker, RegistryEntityUpdateResult::class)[0];
    }

    private function awaitFailure(WorkerHandle $worker): RegistryEntityUpdateFailed
    {
        EventLoopTicks::settleUntil(static fn(): bool => self::listSentOfType($worker, RegistryEntityUpdateFailed::class) !== []);

        return self::listSentOfType($worker, RegistryEntityUpdateFailed::class)[0];
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

    private static function createRequest(EntityRegistryUpdate $update, string $entityId = 'light.hall'): RegistryEntityUpdateRequest
    {
        return new RegistryEntityUpdateRequest(new CorrelationId('edit'), ResourceScope::forApp(new AppId('demo')), new EntityId($entityId), $update);
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
