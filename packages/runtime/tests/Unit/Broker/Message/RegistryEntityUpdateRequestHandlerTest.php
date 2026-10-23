<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Broker\Message;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;
use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\Exception\RegistryEditError;
use Stewart\Contracts\Exposure\ExposedEntityKey;
use Stewart\Contracts\Registry\EntityAlias;
use Stewart\Contracts\Registry\LabelId;
use Stewart\Contracts\Registry\RegisteredEntity;
use Stewart\Contracts\Registry\Update\EntityRegistryUpdate;
use Stewart\Runtime\App\AppDefinition;
use Stewart\Runtime\App\Collection\AppDefinitionCollection;
use Stewart\Runtime\Broker\AppMetrics;
use Stewart\Runtime\Broker\Collection\WorkerSlotCollection;
use Stewart\Runtime\Broker\HaCallSlots;
use Stewart\Runtime\Broker\Message\RegistryEntityUpdateRequestHandler;
use Stewart\Runtime\Broker\OutboxLimits;
use Stewart\Runtime\Broker\WorkerHandle;
use Stewart\Runtime\Broker\WorkerSlot;
use Stewart\Runtime\Config\HaCallPolicy;
use Stewart\Runtime\Ipc\Message\RegistryEntityUpdateRequest;
use Stewart\Runtime\Ipc\Message\RegistryEntityUpdateResult;
use Stewart\Runtime\Ipc\Message\RequestFailed;
use Stewart\Runtime\Model\CorrelationId;
use Stewart\Runtime\Model\HaCallOutcome;
use Stewart\Runtime\Model\ResourceScope;
use Stewart\Runtime\Model\WorkerId;
use Stewart\Runtime\Tests\Fixtures\Apps\Demo;
use Stewart\Runtime\Tests\Fixtures\Broker\FakeHaSession;
use Stewart\Runtime\Tests\Fixtures\Broker\FakeHomeAssistant;
use Stewart\Runtime\Tests\Fixtures\Broker\FakeWorkerProcess;
use Stewart\Runtime\Tests\Fixtures\Ipc\FakeWorkerTransport;
use Stewart\Testing\Logging\RecordingLogger;
use Stewart\Testing\Time\EventLoopTicks;
use Stewart\Testing\Time\ManualTimers;

#[CoversClass(RegistryEntityUpdateRequestHandler::class)]
#[CoversClass(HaCallOutcome::class)]
final class RegistryEntityUpdateRequestHandlerTest extends TestCase
{
    private FakeHaSession $session;

    private FakeHomeAssistant $homeAssistant;

    private AppMetrics $metrics;

    private ManualTimers $timers;

    private HaCallSlots $slots;

    protected function setUp(): void
    {
        $this->timers = new ManualTimers();
        $this->session = new FakeHaSession();
        $this->homeAssistant = FakeHomeAssistant::createConnected();
        $this->homeAssistant->seedRegistryEntry(new RegisteredEntity(
            new EntityId('light.hall'),
            labelIds: [new LabelId('night'), new LabelId('hue')],
            name: 'Hall',
            aliases: [EntityAlias::named('Hall lamp')],
        ));
        $this->metrics = new AppMetrics(
            WorkerSlotCollection::fromList([new WorkerSlot(new WorkerId(0), AppDefinitionCollection::keyedByAppId([new AppDefinition(new AppId('demo'), Demo::class)]))]),
            $this->timers->clock,
        );
    }

    public function testUpdateAnswersWithUpdatedEntry(): void
    {
        $worker = $this->createHandle();

        $this->createHandler(new HaCallPolicy(perWorker: 0, total: 0))->handle($worker, self::createRequest(new EntityRegistryUpdate()->withName('Hallway')));

        self::assertSame('Hallway', $this->awaitResult($worker)->entity->name);
        self::assertSame(0, $this->slots->inFlight);
    }

    public function testListEditsMergeWithCurrentEntry(): void
    {
        $worker = $this->createHandle();
        $update = new EntityRegistryUpdate()->withAddedLabels('battery')->withRemovedLabels('hue')->withAddedAliases(EntityAlias::entityName());

        $this->createHandler(new HaCallPolicy(perWorker: 0, total: 0))->handle($worker, self::createRequest($update));

        $entity = $this->awaitResult($worker)->entity;
        self::assertSame(['night', 'battery'], $entity->listLabelIds()->toStrings());
        self::assertEquals([EntityAlias::named('Hall lamp'), EntityAlias::entityName()], $entity->aliases);
        self::assertSame(['night', 'battery'], $this->homeAssistant->listSentRegistryUpdates()[0]['labels'] ?? null, 'The added and removed labels are resolved before sending.');
    }

    public function testEditOfExposedEntityWarnsAboutHandle(): void
    {
        $logger = new RecordingLogger();
        $worker = $this->createHandle();
        $this->homeAssistant->exposeSensor(new AppId('climate'), new ExposedEntityKey('average'), new EntityId('light.hall'));

        $this->createHandler(new HaCallPolicy(perWorker: 0, total: 0), $logger)->handle($worker, self::createRequest(new EntityRegistryUpdate()->withName('Hallway')));

        self::assertSame('Hallway', $this->awaitResult($worker)->entity->name);
        self::assertSame(
            ['Editing the registry entry of an entity a Stewart app exposes; its handle owns the name and icon'],
            $logger->listMessagesAt(LogLevel::WARNING),
        );
    }

    public function testEditOfOtherEntityDoesNotWarn(): void
    {
        $logger = new RecordingLogger();
        $worker = $this->createHandle();
        $this->homeAssistant->exposeSensor(new AppId('climate'), new ExposedEntityKey('average'), new EntityId('sensor.average'));

        $this->createHandler(new HaCallPolicy(perWorker: 0, total: 0), $logger)->handle($worker, self::createRequest(new EntityRegistryUpdate()->withName('Hallway')));

        $this->awaitResult($worker);
        self::assertSame([], $logger->listMessagesAt(LogLevel::WARNING));
    }

    public function testUnknownEntityFailsAsNotFound(): void
    {
        $worker = $this->createHandle();

        $this->createHandler(new HaCallPolicy(perWorker: 0, total: 0))->handle($worker, self::createRequest(new EntityRegistryUpdate()->withHidden(true), 'light.gone'));

        self::assertSame(RegistryEditError::NotFound->value, $this->awaitFailure($worker)->details->reason);
    }

    public function testDisconnectedUpdateFailsAsUnreachable(): void
    {
        $worker = $this->createHandle();
        $this->session->connected = false;

        $this->createHandler(new HaCallPolicy(perWorker: 0, total: 0))->handle($worker, self::createRequest(new EntityRegistryUpdate()->withName('Hallway')));

        self::assertSame(RegistryEditError::Unreachable->value, $this->awaitFailure($worker)->details->reason);
    }

    public function testFullCallBudgetRefusesUpdate(): void
    {
        $worker = $this->createHandle();
        $handler = $this->createHandler(new HaCallPolicy(perWorker: 1, total: 0));
        $this->homeAssistant->seedRegistryEntry(new RegisteredEntity(new EntityId('light.hall')));
        $held = $this->homeAssistant->holdReplies();
        $handler->handle($worker, self::createRequest(new EntityRegistryUpdate()->withName('Hall')));
        EventLoopTicks::settle();

        $handler->handle($worker, self::createRequest(new EntityRegistryUpdate()->withName('Hallway')));

        self::assertSame(RegistryEditError::Overloaded->value, $this->awaitFailure($worker)->details->reason);
        self::assertCount(1, $this->homeAssistant->listSentRegistryUpdates());
        $held->open();
        EventLoopTicks::settle();
    }

    public function testDryRunPreviewsWithoutUpdating(): void
    {
        $logger = new RecordingLogger();
        $worker = $this->createHandle();

        $this->createHandler(new HaCallPolicy(perWorker: 0, total: 0, dryRun: true), $logger)->handle($worker, self::createRequest(new EntityRegistryUpdate()->withHidden(true)));

        self::assertTrue($this->awaitResult($worker)->entity->isHidden());
        self::assertSame([], $this->homeAssistant->listSentRegistryUpdates());
        self::assertSame(['Registry entry not updated: ha_calls.dry_run is on'], $logger->listMessagesAt(LogLevel::INFO));
    }

    private function createHandler(HaCallPolicy $policy, ?RecordingLogger $logger = null): RegistryEntityUpdateRequestHandler
    {
        $this->slots = new HaCallSlots($policy, $this->metrics, $this->timers->clock, new NullLogger());

        return new RegistryEntityUpdateRequestHandler($this->session, $this->homeAssistant->client, $this->homeAssistant->exposures, $this->slots, $policy, $logger ?? new NullLogger());
    }

    private function awaitResult(WorkerHandle $worker): RegistryEntityUpdateResult
    {
        EventLoopTicks::settleUntil(static fn(): bool => self::listSentOfType($worker, RegistryEntityUpdateResult::class) !== []);

        return self::listSentOfType($worker, RegistryEntityUpdateResult::class)[0];
    }

    private function awaitFailure(WorkerHandle $worker): RequestFailed
    {
        EventLoopTicks::settleUntil(static fn(): bool => self::listSentOfType($worker, RequestFailed::class) !== []);

        return self::listSentOfType($worker, RequestFailed::class)[0];
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
