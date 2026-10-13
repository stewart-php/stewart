<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Broker;

use Amp\DeferredFuture;
use Amp\Future;
use Psr\Log\NullLogger;
use Stewart\Client\Component\ComponentEventDecoder;
use Stewart\Client\Component\ComponentInstance;
use Stewart\Client\Component\ExposedEntityDefinition;
use Stewart\Client\Connection\ConnectionConfig;
use Stewart\Client\Connection\HaConnection;
use Stewart\Client\Connection\HomeAssistantUrl;
use Stewart\Client\Event\EventDecoder;
use Stewart\Client\HaClient;
use Stewart\Client\Registry\EntityRegistryEntry;
use Stewart\Client\Registry\RegistryDecoder;
use Stewart\Client\State\EntityStateDecoder;
use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\Exposure\ExposedEntityKey;
use Stewart\Contracts\Exposure\ExposedStateChange;
use Stewart\Contracts\Exposure\SensorConfig;
use Stewart\Contracts\Registry\EntityAlias;
use Stewart\Contracts\Registry\RegisteredEntity;
use Stewart\Contracts\State\EntityState;
use Stewart\Contracts\Time\Duration;
use Stewart\Runtime\Broker\Component\ComponentTracker;
use Stewart\Runtime\Broker\Exposure\ExposureLink;
use Stewart\Runtime\Broker\Trigger\HaTriggerLink;
use Stewart\Runtime\Config\ExposeConfig;
use Stewart\Runtime\Kernel\SyntheticServices;
use Stewart\Runtime\Lifecycle\ComponentState;
use Stewart\Runtime\Model\WorkerId;
use Stewart\Support\Wire\TriggerSpec;
use Stewart\Testing\Async\Latch;
use Stewart\Testing\Time\EventLoopTicks;
use Stewart\Testing\Time\ManualTimers;
use Stewart\Testing\Websocket\FakeWebsocketConnection;
use Stewart\Testing\Websocket\FakeWebsocketConnector;

use function Amp\async;

final class FakeHomeAssistant
{
    public const string HA_USER_ID = 'stewart-user';

    public const string CALL_CONTEXT_PREFIX = 'call-';

    public const string FIRE_CONTEXT_PREFIX = 'fire-';

    // Enough loop ticks for a reply to travel the client, the broker and the worker transport.
    private const int REPLY_DELIVERY_TICKS = 20;

    private const array SUCCESS = ['type' => 'result', 'success' => true, 'result' => null];

    public readonly FakeWebsocketConnection $socket;

    public readonly HaClient $client;

    public readonly ComponentTracker $componentTracker;

    public readonly ExposureLink $exposures;

    public readonly HaTriggerLink $triggers;

    private ?Latch $replies = null;

    /** @var list<ReceivedServiceCall> */
    private array $receivedCalls = [];

    /** @var list<ReceivedEventFire> */
    private array $receivedEventFires = [];

    /** @var array<string, array<string, mixed>> */
    private array $registryEntries = [];

    /** @var list<array<string, mixed>> */
    private array $historyRows = [];

    /** @var array<string, mixed>|null */
    private ?array $historyError = null;

    /** @var array<string, string> */
    private array $exposedEntityIds = [];

    /** @var array<int, TriggerSpec> */
    private array $triggersBySubscriptionId = [];

    /** @var list<TriggerSpec> */
    private array $unsubscribedTriggers = [];

    /** @var list<AnsweredExposedCommand> */
    private array $commandAnswers = [];

    /** @var DeferredFuture<ReceivedServiceCall>|null */
    private ?DeferredFuture $nextCall = null;

    /** @var DeferredFuture<RegisteredEntity>|null */
    private ?DeferredFuture $nextRegistryUpdate = null;

    /** @var DeferredFuture<array<string, mixed>>|null */
    private ?DeferredFuture $nextExposedUpdate = null;

    /** @var DeferredFuture<AnsweredExposedCommand>|null */
    private ?DeferredFuture $nextCommandAnswer = null;

    private function __construct(FakeWebsocketConnector $connector, FakeWebsocketConnection $socket)
    {
        $timers = new ManualTimers();
        $this->socket = $socket;
        $this->client = self::createClient($timers, $connector);
        $this->componentTracker = new ComponentTracker($timers->clock);
        $this->exposures = new ExposureLink($this->client, new NullLogger(), $this->componentTracker, new ExposeConfig(ComponentInstance::parse('default'), Duration::seconds(10)));
        $this->triggers = new HaTriggerLink($this->client, new NullLogger());
        $this->answerHomeAssistantCommands();
    }

    public static function createConnected(): self
    {
        $socket = FakeWebsocketConnector::createAuthenticatedConnection();
        $homeAssistant = new self(new FakeWebsocketConnector($socket), $socket);
        $homeAssistant->client->connect();
        $homeAssistant->triggers->resubscribeAll();

        return $homeAssistant;
    }

    public static function createUnreachable(): self
    {
        return new self(FakeWebsocketConnector::createStalled(), new FakeWebsocketConnection());
    }

    public function activateComponent(): void
    {
        $this->componentTracker->recordState(ComponentState::Active, null);
    }

    public function exposeSensor(AppId $appId, ExposedEntityKey $key, EntityId $entityId): void
    {
        $this->activateComponent();
        $this->exposedEntityIds[$appId . '/' . $key] = $entityId->value;
        $this->exposures->exposeEntity(new WorkerId(0), $appId, $key, ExposedEntityDefinition::fromConfig(new SensorConfig(), null), new ExposedStateChange());
    }

    public function addSyntheticServicesTo(SyntheticServices $services): SyntheticServices
    {
        return $services
            ->withService(HaClient::class, $this->client)
            ->withService(ComponentTracker::class, $this->componentTracker)
            ->withService(ExposureLink::class, $this->exposures)
            ->withService(HaTriggerLink::class, $this->triggers);
    }

    public function holdReplies(): Latch
    {
        return $this->replies = new Latch();
    }

    /** @return Future<ReceivedServiceCall> */
    public function waitForNextCall(): Future
    {
        return ($this->nextCall ??= new DeferredFuture())->getFuture();
    }

    /** @return list<ReceivedServiceCall> */
    public function listReceivedCalls(): array
    {
        return $this->receivedCalls;
    }

    /** @return list<ReceivedEventFire> */
    public function listReceivedEventFires(): array
    {
        return $this->receivedEventFires;
    }

    public function seedRegistryEntry(RegisteredEntity $entity): void
    {
        $this->registryEntries[$entity->entityId->value] = [
            'entity_id' => $entity->entityId->value,
            'device_id' => $entity->deviceId?->value,
            'area_id' => $entity->areaId?->value,
            'labels' => array_map(static fn($labelId): string => $labelId->value, $entity->labelIds),
            'name' => $entity->name,
            'entity_category' => $entity->entityCategory,
            'hidden_by' => $entity->hiddenBy,
            'disabled_by' => $entity->disabledBy,
            'icon' => $entity->icon,
            'aliases' => $entity->aliases === null ? null : array_map(static fn(EntityAlias $alias): ?string => $alias->phrase, $entity->aliases),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function listSentRegistryUpdates(): array
    {
        return $this->socket->listSentOfType('config/entity_registry/update');
    }

    /** @return Future<RegisteredEntity> */
    public function waitForNextRegistryUpdate(): Future
    {
        return ($this->nextRegistryUpdate ??= new DeferredFuture())->getFuture();
    }

    public function seedHistory(EntityState ...$states): void
    {
        $this->historyRows = array_values(array_map(static fn(EntityState $state): array => ['s' => $state->state, 'a' => $state->attributes], $states));
    }

    public function failHistory(string $code, string $message): void
    {
        $this->historyError = ['type' => 'result', 'success' => false, 'error' => ['code' => $code, 'message' => $message]];
    }

    /** @return list<TriggerSpec> */
    public function listSubscribedTriggers(): array
    {
        return array_values($this->triggersBySubscriptionId);
    }

    /** @return list<TriggerSpec> */
    public function listUnsubscribedTriggers(): array
    {
        return $this->unsubscribedTriggers;
    }

    /** @return Future<array<string, mixed>> */
    public function waitForNextExposedUpdate(): Future
    {
        return ($this->nextExposedUpdate ??= new DeferredFuture())->getFuture();
    }

    /** @return list<AnsweredExposedCommand> */
    public function listCommandAnswers(): array
    {
        return $this->commandAnswers;
    }

    /** @return Future<AnsweredExposedCommand> */
    public function waitForNextCommandAnswer(): Future
    {
        return ($this->nextCommandAnswer ??= new DeferredFuture())->getFuture();
    }

    private function answerHomeAssistantCommands(): void
    {
        $this->socket->answerEverySend('call_service', $this->answerServiceCall(...));
        $this->socket->answerEverySend('fire_event', $this->answerEventFire(...));
        $this->socket->answerEverySend('config/entity_registry/get', $this->answerRegistryGet(...));
        $this->socket->answerEverySend('config/entity_registry/update', $this->answerRegistryUpdate(...));
        $this->socket->answerEverySend('history/history_during_period', $this->answerHistory(...));
        $this->socket->answerEverySend('subscribe_trigger', $this->answerTriggerSubscribe(...));
        $this->socket->answerEverySend('unsubscribe_events', $this->answerUnsubscribe(...));
        $this->socket->answerEverySend('stewart/entity/upsert', $this->answerExposedUpsert(...));
        $this->socket->answerEverySend('stewart/entity/state', $this->answerExposedState(...));
        $this->socket->answerEverySend('stewart/entity/remove', static fn(array $command): array => ['type' => 'result', 'success' => true, 'result' => ['removed' => true]]);
        $this->socket->answerEverySend('stewart/command/result', $this->answerCommandResult(...));
    }

    /**
     * @param array<string, mixed> $command
     * @return array<string, mixed>|null
     */
    private function answerServiceCall(array $command): ?array
    {
        $call = new ReceivedServiceCall(self::readText($command, 'domain'), self::readText($command, 'service'), self::readFields($command, 'service_data'));
        $this->receivedCalls[] = $call;
        $context = ['id' => self::CALL_CONTEXT_PREFIX . \count($this->receivedCalls), 'parent_id' => null, 'user_id' => self::HA_USER_ID];

        $called = $this->nextCall;
        $this->nextCall = null;

        if ($called !== null) {
            async(static function () use ($called, $call): void {
                EventLoopTicks::settle(self::REPLY_DELIVERY_TICKS);
                $called->complete($call);
            })->ignore();
        }

        return $this->replyWhenReleased($command, ['type' => 'result', 'success' => true, 'result' => ['context' => $context, 'response' => null]]);
    }

    /**
     * @param array<string, mixed> $command
     * @return array<string, mixed>|null
     */
    private function answerEventFire(array $command): ?array
    {
        $this->receivedEventFires[] = new ReceivedEventFire(self::readText($command, 'event_type'), self::readFields($command, 'event_data'));
        $context = ['id' => self::FIRE_CONTEXT_PREFIX . \count($this->receivedEventFires), 'parent_id' => null, 'user_id' => self::HA_USER_ID];

        return $this->replyWhenReleased($command, ['type' => 'result', 'success' => true, 'result' => ['context' => $context]]);
    }

    /**
     * @param array<string, mixed> $command
     * @return array<string, mixed>
     */
    private function answerRegistryGet(array $command): array
    {
        $entry = $this->registryEntries[self::readText($command, 'entity_id')] ?? null;

        return $entry === null ? self::createNotFound() : ['type' => 'result', 'success' => true, 'result' => $entry];
    }

    /**
     * @param array<string, mixed> $command
     * @return array<string, mixed>|null
     */
    private function answerRegistryUpdate(array $command): ?array
    {
        $entityId = self::readText($command, 'entity_id');

        if (!isset($this->registryEntries[$entityId])) {
            return self::createNotFound();
        }

        $fields = $command;
        unset($fields['id'], $fields['type']);
        $entry = $this->registryEntries[$entityId] = [...$this->registryEntries[$entityId], ...$fields];

        $updated = $this->nextRegistryUpdate;
        $this->nextRegistryUpdate = null;
        $updated?->complete(EntityRegistryEntry::fromArray($entry)->toRegisteredEntity());

        return $this->replyWhenReleased($command, ['type' => 'result', 'success' => true, 'result' => ['entity_entry' => $entry]]);
    }

    /**
     * @param array<string, mixed> $command
     * @return array<string, mixed>
     */
    private function answerHistory(array $command): array
    {
        $entityIds = self::readFields($command, 'entity_ids');
        $entityId = \is_string($entityIds[0] ?? null) ? $entityIds[0] : '';

        return $this->historyError ?? ['type' => 'result', 'success' => true, 'result' => [$entityId => $this->historyRows]];
    }

    /**
     * @param array<string, mixed> $command
     * @return array<string, mixed>
     */
    private function answerTriggerSubscribe(array $command): array
    {
        $triggers = $command['trigger'] ?? [];
        $variables = $command['variables'] ?? [];

        if (\is_int($command['id'] ?? null) && \is_array($triggers)) {
            /** @var list<array<string, mixed>> $triggers */
            $this->triggersBySubscriptionId[$command['id']] = TriggerSpec::fromSpec($triggers, \is_array($variables) ? $variables : []);
        }

        return self::SUCCESS;
    }

    /**
     * @param array<string, mixed> $command
     * @return array<string, mixed>
     */
    private function answerUnsubscribe(array $command): array
    {
        $subscriptionId = $command['subscription'] ?? null;
        $spec = \is_int($subscriptionId) ? $this->triggersBySubscriptionId[$subscriptionId] ?? null : null;

        if ($spec !== null) {
            $this->unsubscribedTriggers[] = $spec;
        }

        return self::SUCCESS;
    }

    /**
     * @param array<string, mixed> $command
     * @return array<string, mixed>
     */
    private function answerExposedUpsert(array $command): array
    {
        $app = self::readText($command, 'app');
        $key = self::readText($command, 'key');

        if (self::readText($command, 'platform') === 'action') {
            return ['type' => 'result', 'success' => true, 'result' => ['service' => \sprintf('stewart.%s_%s', str_replace('-', '_', $app), $key)]];
        }

        $entityId = $this->exposedEntityIds[$app . '/' . $key] ?? \sprintf('%s.%s_%s', self::readText($command, 'platform'), $app, $key);

        return ['type' => 'result', 'success' => true, 'result' => [
            'entity_id' => str_replace('-', '_', $entityId),
            'state' => $command['state'] ?? null,
            'attributes' => [],
            'available' => true,
        ]];
    }

    /**
     * @param array<string, mixed> $command
     * @return array<string, mixed>
     */
    private function answerExposedState(array $command): array
    {
        $updated = $this->nextExposedUpdate;
        $this->nextExposedUpdate = null;
        $updated?->complete($command);

        return self::SUCCESS;
    }

    /**
     * @param array<string, mixed> $command
     * @return array<string, mixed>
     */
    private function answerCommandResult(array $command): array
    {
        $message = $command['message'] ?? null;
        $answer = new AnsweredExposedCommand(self::readText($command, 'command_id'), ($command['ok'] ?? false) === true ? null : (\is_string($message) ? $message : ''));
        $this->commandAnswers[] = $answer;

        $answered = $this->nextCommandAnswer;
        $this->nextCommandAnswer = null;
        $answered?->complete($answer);

        return self::SUCCESS;
    }

    /**
     * @param array<string, mixed> $command
     * @param array<string, mixed> $reply
     * @return array<string, mixed>|null
     */
    private function replyWhenReleased(array $command, array $reply): ?array
    {
        $replies = $this->replies;

        if ($replies === null || $replies->isOpen()) {
            return $reply;
        }

        $id = $command['id'] ?? null;
        $socket = $this->socket;

        async(static function () use ($replies, $socket, $id, $reply): void {
            $replies->waitUntilOpen();
            $socket->queueFrame(['id' => $id, ...$reply]);
        })->ignore();

        return null;
    }

    /** @param array<string, mixed> $command */
    private static function readText(array $command, string $key): string
    {
        $value = $command[$key] ?? null;

        return \is_scalar($value) ? (string) $value : '';
    }

    /**
     * @param array<string, mixed> $command
     * @return array<string, mixed>
     */
    private static function readFields(array $command, string $key): array
    {
        $value = $command[$key] ?? null;
        $fields = [];

        foreach (\is_array($value) ? $value : [] as $field => $fieldValue) {
            $fields[(string) $field] = $fieldValue;
        }

        return $fields;
    }

    /** @return array<string, mixed> */
    private static function createNotFound(): array
    {
        return ['type' => 'result', 'success' => false, 'error' => ['code' => 'not_found', 'message' => 'Entity not found.']];
    }

    private static function createClient(ManualTimers $timers, FakeWebsocketConnector $connector): HaClient
    {
        $connection = new HaConnection(
            new ConnectionConfig(
                url: HomeAssistantUrl::parse('http://home-assistant.invalid:8123'),
                token: 'test-token',
                connectTimeout: Duration::seconds(1),
                commandTimeout: Duration::seconds(1),
                heartbeatInterval: null,
            ),
            $timers,
            new NullLogger(),
            $connector,
        );

        return new HaClient($connection, new EventDecoder(new EntityStateDecoder()), new EntityStateDecoder(), new RegistryDecoder(), new ComponentEventDecoder(), new NullLogger());
    }
}
