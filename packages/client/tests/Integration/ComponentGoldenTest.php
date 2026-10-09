<?php

declare(strict_types=1);

namespace Stewart\Client\Tests\Integration;

use Amp\DeferredFuture;
use Amp\TimeoutCancellation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Stewart\Client\Component\Collection\ExposedEntityAddressCollection;
use Stewart\Client\Component\ComponentCommand;
use Stewart\Client\Component\ComponentCommandAction;
use Stewart\Client\Component\ComponentCommandAnswer;
use Stewart\Client\Component\ComponentErrorCode;
use Stewart\Client\Component\ComponentEventDecoder;
use Stewart\Client\Component\ComponentInstance;
use Stewart\Client\Component\ComponentSessionEvent;
use Stewart\Client\Component\ComponentSessionRequest;
use Stewart\Client\Component\ComponentVersion;
use Stewart\Client\Component\ExposedEntityAddress;
use Stewart\Client\Component\ExposedEntityDefinition;
use Stewart\Client\Component\ExposedEntityReconcile;
use Stewart\Client\Component\ReconcileResultReader;
use Stewart\Client\Component\SessionReplaced;
use Stewart\Client\Connection\Command\Component\AnswerComponentCommand;
use Stewart\Client\Connection\Command\Component\GetComponentVersion;
use Stewart\Client\Connection\Command\Component\ReconcileExposedEntities;
use Stewart\Client\Connection\Command\Component\RemoveExposedEntity;
use Stewart\Client\Connection\Command\Component\SubscribeComponentSession;
use Stewart\Client\Connection\Command\Component\UpdateExposedEntityState;
use Stewart\Client\Connection\Command\Component\UpsertExposedEntity;
use Stewart\Client\Connection\ConnectionConfig;
use Stewart\Client\Exception\HaClientError;
use Stewart\Client\Exception\HaClientException;
use Stewart\Client\HaClient;
use Stewart\Client\Tests\Fixtures\Component\ComponentGolden;
use Stewart\Client\Tests\Fixtures\FakeHaServer;
use Stewart\Contracts\App\AppId;
use Stewart\Contracts\App\Collection\AppIdCollection;
use Stewart\Contracts\Exposure\BinarySensorConfig;
use Stewart\Contracts\Exposure\BinarySensorDeviceClass;
use Stewart\Contracts\Exposure\ButtonConfig;
use Stewart\Contracts\Exposure\DateConfig;
use Stewart\Contracts\Exposure\DateTimeConfig;
use Stewart\Contracts\Exposure\DeviceInfo;
use Stewart\Contracts\Exposure\EntityCategory;
use Stewart\Contracts\Exposure\ExposedEntityConfig;
use Stewart\Contracts\Exposure\ExposedEntityKey;
use Stewart\Contracts\Exposure\ExposedEntitySnapshot;
use Stewart\Contracts\Exposure\ExposedState;
use Stewart\Contracts\Exposure\ExposedStateChange;
use Stewart\Contracts\Exposure\NumberConfig;
use Stewart\Contracts\Exposure\NumberDeviceClass;
use Stewart\Contracts\Exposure\NumberMode;
use Stewart\Contracts\Exposure\SelectConfig;
use Stewart\Contracts\Exposure\SensorConfig;
use Stewart\Contracts\Exposure\SensorDeviceClass;
use Stewart\Contracts\Exposure\SensorStateClass;
use Stewart\Contracts\Exposure\SwitchConfig;
use Stewart\Contracts\Exposure\TextConfig;
use Stewart\Contracts\Exposure\TextMode;
use Stewart\Contracts\Exposure\TimeConfig;
use Stewart\Contracts\Time\Duration;
use Stewart\Support\Time\RevoltTimers;
use Stewart\Testing\Exception\AssertsReason;

#[CoversClass(GetComponentVersion::class)]
#[CoversClass(SubscribeComponentSession::class)]
#[CoversClass(ComponentVersion::class)]
#[CoversClass(ComponentEventDecoder::class)]
#[CoversClass(UpsertExposedEntity::class)]
#[CoversClass(UpdateExposedEntityState::class)]
#[CoversClass(RemoveExposedEntity::class)]
#[CoversClass(ReconcileExposedEntities::class)]
#[CoversClass(ReconcileResultReader::class)]
#[CoversClass(ExposedEntityDefinition::class)]
#[CoversClass(ExposedStateChange::class)]
#[CoversClass(ExposedEntitySnapshot::class)]
#[CoversClass(ComponentErrorCode::class)]
#[CoversClass(AnswerComponentCommand::class)]
#[CoversClass(ComponentCommandAnswer::class)]
final class ComponentGoldenTest extends TestCase
{
    use AssertsReason;

    private const float WAIT_SECONDS = 5;

    private FakeHaServer $server;

    private ?HaClient $client = null;

    protected function setUp(): void
    {
        $this->server = FakeHaServer::start();
    }

    protected function tearDown(): void
    {
        $this->client?->close();
        $this->server->stop();
    }

    public function testVersionMatchesGolden(): void
    {
        $golden = ComponentGolden::loadGolden('version');
        $this->server->replayGolden($golden);

        $version = $this->connectClient()->findComponentVersion();

        self::assertSame([$golden->request], $this->listReceivedWithoutIds('stewart/version'));
        self::assertSame('0.9.0', $version?->componentVersion);
        self::assertSame(1, $version->protocol);
    }

    public function testSessionSubscribeMatchesGolden(): void
    {
        $golden = ComponentGolden::loadGolden('session-subscribe');
        $this->server->replayGolden($golden);

        $this->connectClient()->subscribeComponentSession(self::createSessionRequest(), static function (): void {});

        self::assertSame([$golden->request], $this->listReceivedWithoutIds('stewart/session/subscribe'));
    }

    public function testProtocolMismatchGoldenIsRefusal(): void
    {
        $this->server->replayGolden(ComponentGolden::loadGolden('session-subscribe.protocol-mismatch'));
        $client = $this->connectClient();

        $exception = $this->assertThrowsReason(
            HaClientError::CommandRejected,
            static fn() => $client->subscribeComponentSession(self::createSessionRequest(), static function (): void {}),
        );

        self::assertInstanceOf(HaClientException::class, $exception);
        self::assertSame('protocol_mismatch', $exception->findErrorCode());
    }

    public function testSessionReplacedGoldenIsDecoded(): void
    {
        $this->server->replayGolden(ComponentGolden::loadGolden('session-subscribe'));
        /** @var DeferredFuture<ComponentSessionEvent> $received */
        $received = new DeferredFuture();

        $subscriptionId = $this->connectClient()->subscribeComponentSession(
            self::createSessionRequest(),
            static function (ComponentSessionEvent $event) use ($received): void {
                $received->complete($event);
            },
        );
        $this->server->pushEvent($subscriptionId, ComponentGolden::loadGolden('event-session-replaced')->requireEvent());

        self::assertInstanceOf(SessionReplaced::class, $received->getFuture()->await(new TimeoutCancellation(self::WAIT_SECONDS)));
    }

    public function testSwitchCommandGoldenIsDecoded(): void
    {
        $command = $this->receiveCommandGolden('event-command.switch');

        self::assertSame('lights', $command->appId->value);
        self::assertSame('night_mode', $command->key->value);
        self::assertSame(ComponentCommandAction::TurnOn, $command->action);
        self::assertSame('9e2d6b4f1c8a4e7d8b3f5a0c6d1e2f3a', $command->context->userId);
    }

    public function testButtonCommandGoldenIsDecoded(): void
    {
        $command = $this->receiveCommandGolden('event-command.button');

        self::assertSame('all_off', $command->key->value);
        self::assertSame(ComponentCommandAction::Press, $command->action);
    }

    /** @param array<string, mixed> $data */
    #[DataProvider('provideValueCommandGoldens')]
    public function testValueCommandGoldenIsDecoded(string $golden, ComponentCommandAction $action, array $data): void
    {
        $command = $this->receiveCommandGolden($golden);

        self::assertSame($action, $command->action);
        self::assertSame($data, $command->data);
    }

    /** @return iterable<string, array{string, ComponentCommandAction, array<string, mixed>}> */
    public static function provideValueCommandGoldens(): iterable
    {
        yield 'number' => ['event-command.number', ComponentCommandAction::SetValue, ['value' => 1.5]];
        yield 'select' => ['event-command.select', ComponentCommandAction::SelectOption, ['option' => 'comfort']];
        yield 'text' => ['event-command.text', ComponentCommandAction::SetValue, ['value' => 'Good morning']];
        yield 'time' => ['event-command.time', ComponentCommandAction::SetValue, ['value' => '07:00:00']];
        yield 'date' => ['event-command.date', ComponentCommandAction::SetValue, ['value' => '2026-10-15']];
        yield 'datetime' => ['event-command.datetime', ComponentCommandAction::SetValue, ['value' => '2026-10-09T18:30:00+00:00']];
    }

    public function testAcceptedAnswerMatchesGolden(): void
    {
        $golden = ComponentGolden::loadGolden('command-result.ok');
        $this->server->replayGolden($golden);

        $answered = $this->connectClient()->answerComponentCommand(ComponentCommandAnswer::accept('3f2b9c0e8d7a4f61'));

        self::assertTrue($answered);
        self::assertSame([$golden->request], $this->listReceivedWithoutIds('stewart/command/result'));
    }

    public function testRejectedAnswerMatchesGolden(): void
    {
        $golden = ComponentGolden::loadGolden('command-result.rejected');
        $this->server->replayGolden($golden);

        $this->connectClient()->answerComponentCommand(
            ComponentCommandAnswer::reject('3f2b9c0e8d7a4f61', 'Night mode cannot start while the alarm is armed.'),
        );

        self::assertSame([$golden->request], $this->listReceivedWithoutIds('stewart/command/result'));
    }

    public function testAnswerToUnknownCommandIsFalse(): void
    {
        $this->server->replayGolden(ComponentGolden::loadGolden('command-result.not-found'));

        self::assertFalse($this->connectClient()->answerComponentCommand(ComponentCommandAnswer::accept('3f2b9c0e8d7a4f61')));
    }

    public function testSensorUpsertMatchesGolden(): void
    {
        $golden = ComponentGolden::loadGolden('entity-upsert.sensor');
        $this->server->replayGolden($golden);

        $snapshot = $this->connectClient()->upsertExposedEntity(
            self::createAddress('climate', 'average_temperature'),
            ExposedEntityDefinition::fromConfig(self::createTemperatureConfig(), null),
            new ExposedStateChange(new ExposedState(21.4), ['sources' => ['sensor.kitchen_temperature', 'sensor.hall_temperature']]),
        );

        self::assertSame([$golden->request], $this->listReceivedWithoutIds('stewart/entity/upsert'));
        self::assertSame('sensor.stewart_climate_average_temperature', $snapshot->entityId->value);
        self::assertSame(21.4, $snapshot->state->value);
        self::assertTrue($snapshot->available);
    }

    public function testNumberUpsertMatchesGolden(): void
    {
        $golden = ComponentGolden::loadGolden('entity-upsert.number');
        $this->server->replayGolden($golden);
        $config = new NumberConfig(-3, 3, 0.5, NumberMode::Slider, NumberDeviceClass::Temperature, '°C', name: 'Target offset', icon: 'mdi:thermometer-plus');

        $snapshot = $this->connectClient()->upsertExposedEntity(
            self::createAddress('climate', 'target_offset'),
            ExposedEntityDefinition::fromConfig($config, null),
            new ExposedStateChange(new ExposedState(0.5)),
        );

        self::assertSame([$golden->request], $this->listReceivedWithoutIds('stewart/entity/upsert'));
        self::assertSame('number.stewart_climate_target_offset', $snapshot->entityId->value);
    }

    public function testSelectUpsertMatchesGolden(): void
    {
        $golden = ComponentGolden::loadGolden('entity-upsert.select');
        $this->server->replayGolden($golden);

        $snapshot = $this->connectClient()->upsertExposedEntity(
            self::createAddress('heating', 'mode'),
            ExposedEntityDefinition::fromConfig(new SelectConfig(['eco', 'comfort', 'away'], name: 'Mode', icon: 'mdi:radiator'), null),
            new ExposedStateChange(new ExposedState('eco')),
        );

        self::assertSame([$golden->request], $this->listReceivedWithoutIds('stewart/entity/upsert'));
        self::assertSame('eco', $snapshot->state->value);
    }

    #[DataProvider('provideTextAndCalendarUpserts')]
    public function testTextAndCalendarUpsertsMatchGoldens(string $goldenName, ExposedEntityAddress $address, ExposedEntityConfig $config, ExposedState $state): void
    {
        $golden = ComponentGolden::loadGolden($goldenName);
        $this->server->replayGolden($golden);

        $this->connectClient()->upsertExposedEntity($address, ExposedEntityDefinition::fromConfig($config, null), new ExposedStateChange($state));

        self::assertSame([$golden->request], $this->listReceivedWithoutIds('stewart/entity/upsert'));
    }

    /** @return iterable<string, array{string, ExposedEntityAddress, ExposedEntityConfig, ExposedState}> */
    public static function provideTextAndCalendarUpserts(): iterable
    {
        yield 'text' => [
            'entity-upsert.text',
            self::createAddress('notify', 'greeting'),
            new TextConfig(1, 40, '^[A-Za-z ,!]+$', TextMode::Text, name: 'Greeting', icon: 'mdi:message-text'),
            new ExposedState('Hello'),
        ];
        yield 'time' => ['entity-upsert.time', self::createAddress('wakeup', 'alarm'), new TimeConfig('Alarm', 'mdi:alarm'), new ExposedState('06:45:00')];
        yield 'date' => ['entity-upsert.date', self::createAddress('garden', 'next_mowing'), new DateConfig('Next mowing', 'mdi:mower'), new ExposedState('2026-10-12')];
        yield 'datetime' => [
            'entity-upsert.datetime',
            self::createAddress('garden', 'last_watered'),
            new DateTimeConfig('Last watered', 'mdi:watering-can'),
            new ExposedState('2026-10-09T07:15:00+02:00'),
        ];
    }

    public function testBinarySensorUpsertMatchesGolden(): void
    {
        $golden = ComponentGolden::loadGolden('entity-upsert.binary-sensor');
        $this->server->replayGolden($golden);

        $snapshot = $this->connectClient()->upsertExposedEntity(
            self::createAddress('presence', 'anyone_home'),
            ExposedEntityDefinition::fromConfig(new BinarySensorConfig(BinarySensorDeviceClass::Occupancy, name: 'Anyone home'), null),
            new ExposedStateChange(new ExposedState(false)),
        );

        self::assertSame([$golden->request], $this->listReceivedWithoutIds('stewart/entity/upsert'));
        self::assertFalse($snapshot->state->value);
    }

    public function testSwitchUpsertMatchesGolden(): void
    {
        $golden = ComponentGolden::loadGolden('entity-upsert.switch');
        $this->server->replayGolden($golden);

        $snapshot = $this->connectClient()->upsertExposedEntity(
            self::createAddress('lights', 'night_mode'),
            ExposedEntityDefinition::fromConfig(new SwitchConfig(name: 'Night mode', icon: 'mdi:weather-night', entityCategory: EntityCategory::Config), null),
            new ExposedStateChange(new ExposedState(true)),
        );

        self::assertSame([$golden->request], $this->listReceivedWithoutIds('stewart/entity/upsert'));
        self::assertTrue($snapshot->state->value);
    }

    public function testButtonUpsertMatchesGolden(): void
    {
        $golden = ComponentGolden::loadGolden('entity-upsert.button');
        $this->server->replayGolden($golden);

        $snapshot = $this->connectClient()->upsertExposedEntity(
            self::createAddress('lights', 'all_off'),
            ExposedEntityDefinition::fromConfig(new ButtonConfig(name: 'All off', icon: 'mdi:lightbulb-group-off'), null),
            new ExposedStateChange(),
        );

        self::assertSame([$golden->request], $this->listReceivedWithoutIds('stewart/entity/upsert'));
        self::assertNull($snapshot->state->value);
    }

    public function testDeviceOverrideUpsertMatchesGolden(): void
    {
        $golden = ComponentGolden::loadGolden('entity-upsert.device-override');
        $this->server->replayGolden($golden);
        $config = new SensorConfig(SensorDeviceClass::Moisture, '%', SensorStateClass::Measurement, name: 'Soil moisture');

        $snapshot = $this->connectClient()->upsertExposedEntity(
            self::createAddress('garden', 'soil_moisture'),
            ExposedEntityDefinition::fromConfig($config, new DeviceInfo('greenhouse', 'Greenhouse', manufacturer: 'Stewart')),
            new ExposedStateChange(new ExposedState(38)),
        );

        self::assertSame([$golden->request], $this->listReceivedWithoutIds('stewart/entity/upsert'));
        self::assertSame('sensor.greenhouse_soil_moisture', $snapshot->entityId->value);
    }

    public function testInvalidStateUpsertMatchesGolden(): void
    {
        $golden = ComponentGolden::loadGolden('entity-upsert.invalid-state');
        $this->server->replayGolden($golden);

        $exception = $this->captureUpsertFailure(
            self::createAddress('presence', 'anyone_home'),
            new BinarySensorConfig(BinarySensorDeviceClass::Occupancy, name: 'Anyone home'),
            new ExposedState('yes'),
        );

        self::assertSame([$golden->request], $this->listReceivedWithoutIds('stewart/entity/upsert'));
        self::assertSame(ComponentErrorCode::InvalidState, ComponentErrorCode::tryFromException($exception));
    }

    public function testInvalidConfigGoldenIsComponentErrorCode(): void
    {
        $this->server->replayGolden(ComponentGolden::loadGolden('entity-upsert.invalid-config'));

        $exception = $this->captureUpsertFailure(self::createAddress('climate', 'average_temperature'), self::createTemperatureConfig(), new ExposedState(21.4));

        self::assertSame(ComponentErrorCode::InvalidConfig, ComponentErrorCode::tryFromException($exception));
    }

    public function testNoSessionGoldenIsComponentErrorCode(): void
    {
        $golden = ComponentGolden::loadGolden('entity-upsert.no-session');
        $this->server->replayGolden($golden);

        $exception = $this->captureUpsertFailure(self::createAddress('climate', 'average_temperature'), self::createTemperatureConfig(), new ExposedState(21.4));

        self::assertSame(ComponentErrorCode::NoSession, ComponentErrorCode::tryFromException($exception));
    }

    public function testStateUpdateMatchesGolden(): void
    {
        $golden = ComponentGolden::loadGolden('entity-state');
        $this->server->replayGolden($golden);

        $this->connectClient()->updateExposedEntityState(
            self::createAddress('climate', 'average_temperature'),
            new ExposedStateChange(new ExposedState(21.9), ['sources' => ['sensor.kitchen_temperature']]),
        );

        self::assertSame([$golden->request], $this->listReceivedWithoutIds('stewart/entity/state'));
    }

    public function testButtonStateIsInvalid(): void
    {
        $golden = ComponentGolden::loadGolden('entity-state.button');
        $this->server->replayGolden($golden);
        $client = $this->connectClient();

        $exception = $this->assertThrowsReason(
            HaClientError::CommandRejected,
            static fn() => $client->updateExposedEntityState(self::createAddress('lights', 'all_off'), new ExposedStateChange(new ExposedState(null))),
        );

        self::assertSame([$golden->request], $this->listReceivedWithoutIds('stewart/entity/state'));
        self::assertInstanceOf(HaClientException::class, $exception);
        self::assertSame(ComponentErrorCode::InvalidState, ComponentErrorCode::tryFromException($exception));
    }

    public function testStateUpdateOfUnknownEntityIsNotFound(): void
    {
        $golden = ComponentGolden::loadGolden('entity-state.not-found');
        $this->server->replayGolden($golden);
        $client = $this->connectClient();

        $exception = $this->assertThrowsReason(
            HaClientError::CommandRejected,
            static fn() => $client->updateExposedEntityState(self::createAddress('lights', 'night_mode'), new ExposedStateChange(available: false)),
        );

        self::assertSame([$golden->request], $this->listReceivedWithoutIds('stewart/entity/state'));
        self::assertInstanceOf(HaClientException::class, $exception);
        self::assertSame(ComponentErrorCode::NotFound, ComponentErrorCode::tryFromException($exception));
    }

    public function testRemoveMatchesGolden(): void
    {
        $golden = ComponentGolden::loadGolden('entity-remove');
        $this->server->replayGolden($golden);

        self::assertTrue($this->connectClient()->removeExposedEntity(self::createAddress('lights', 'night_mode')));
        self::assertSame([$golden->request], $this->listReceivedWithoutIds('stewart/entity/remove'));
    }

    public function testRemoveOfMissingEntityIsFalse(): void
    {
        $this->server->replayGolden(ComponentGolden::loadGolden('entity-remove.missing'));

        self::assertFalse($this->connectClient()->removeExposedEntity(self::createAddress('lights', 'night_mode')));
    }

    public function testReconcileMatchesGolden(): void
    {
        $golden = ComponentGolden::loadGolden('entity-reconcile');
        $this->server->replayGolden($golden);

        $removed = $this->connectClient()->reconcileExposedEntities(new ExposedEntityReconcile(
            ComponentInstance::parse('default'),
            ExposedEntityAddressCollection::fromAddresses([
                self::createAddress('climate', 'average_temperature'),
                self::createAddress('lights', 'night_mode'),
            ]),
            AppIdCollection::fromIds([new AppId('heating')]),
        ));

        self::assertSame([$golden->request], $this->listReceivedWithoutIds('stewart/entity/reconcile'));
        self::assertSame(['binary_sensor.stewart_presence_anyone_home'], $removed->toStrings());
    }

    private function receiveCommandGolden(string $name): ComponentCommand
    {
        $this->server->replayGolden(ComponentGolden::loadGolden('session-subscribe'));
        /** @var DeferredFuture<ComponentSessionEvent> $received */
        $received = new DeferredFuture();

        $subscriptionId = $this->connectClient()->subscribeComponentSession(
            self::createSessionRequest(),
            static function (ComponentSessionEvent $event) use ($received): void {
                $received->complete($event);
            },
        );
        $this->server->pushEvent($subscriptionId, ComponentGolden::loadGolden($name)->requireEvent());
        $command = $received->getFuture()->await(new TimeoutCancellation(self::WAIT_SECONDS));
        self::assertInstanceOf(ComponentCommand::class, $command);

        return $command;
    }

    private function captureUpsertFailure(ExposedEntityAddress $address, ExposedEntityConfig $config, ExposedState $state): HaClientException
    {
        $client = $this->connectClient();
        $exception = $this->assertThrowsReason(
            HaClientError::CommandRejected,
            static fn() => $client->upsertExposedEntity($address, ExposedEntityDefinition::fromConfig($config, null), new ExposedStateChange($state)),
        );
        self::assertInstanceOf(HaClientException::class, $exception);

        return $exception;
    }

    private static function createAddress(string $appId, string $key): ExposedEntityAddress
    {
        return new ExposedEntityAddress(ComponentInstance::parse('default'), new AppId($appId), new ExposedEntityKey($key));
    }

    private static function createTemperatureConfig(): SensorConfig
    {
        return new SensorConfig(SensorDeviceClass::Temperature, '°C', SensorStateClass::Measurement, 1, name: 'Average temperature');
    }

    private static function createSessionRequest(): ComponentSessionRequest
    {
        return new ComponentSessionRequest(ComponentInstance::parse('default'), '0.9.0', Duration::seconds(5));
    }

    /** @return list<array<array-key, mixed>> */
    private function listReceivedWithoutIds(string $type): array
    {
        return array_map(static function (array $command): array {
            unset($command['id']);

            return $command;
        }, $this->server->listReceivedCommands($type));
    }

    private function connectClient(): HaClient
    {
        $this->client = HaClient::fromConnectionConfig(
            new ConnectionConfig(
                url: $this->server->getUrl(),
                token: 'test-token',
                connectTimeout: Duration::seconds(5),
                commandTimeout: Duration::seconds(5),
                heartbeatInterval: null,
            ),
            new RevoltTimers(),
        );
        $this->client->connect();

        return $this->client;
    }
}
