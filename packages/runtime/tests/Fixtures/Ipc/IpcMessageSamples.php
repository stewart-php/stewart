<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Ipc;

use Stewart\Contracts\App\AppId;
use Stewart\Contracts\App\Collection\AppIdCollection;
use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\Event\EventOrigin;
use Stewart\Contracts\Event\HaEvent;
use Stewart\Contracts\Exception\EventFireException;
use Stewart\Contracts\Exception\ExposureException;
use Stewart\Contracts\Exception\HistoryException;
use Stewart\Contracts\Exception\ServiceCallException;
use Stewart\Contracts\Exposure\Command\SwitchAction;
use Stewart\Contracts\Exposure\Command\SwitchCommand;
use Stewart\Contracts\Exposure\DeviceInfo;
use Stewart\Contracts\Exposure\ExposedEntityKey;
use Stewart\Contracts\Exposure\ExposedEntitySnapshot;
use Stewart\Contracts\Exposure\ExposedState;
use Stewart\Contracts\Exposure\ExposedStateChange;
use Stewart\Contracts\Exposure\SensorConfig;
use Stewart\Contracts\Exposure\SensorDeviceClass;
use Stewart\Contracts\Exposure\SensorStateClass;
use Stewart\Contracts\History\Collection\HistoricalStateCollection;
use Stewart\Contracts\History\HistoryDetail;
use Stewart\Contracts\History\HistoryWindow;
use Stewart\Contracts\Mqtt\MqttMessage;
use Stewart\Contracts\Mqtt\MqttQos;
use Stewart\Contracts\Registry\Area;
use Stewart\Contracts\Registry\AreaId;
use Stewart\Contracts\Registry\Collection\AreaCollection;
use Stewart\Contracts\Registry\Collection\DeviceCollection;
use Stewart\Contracts\Registry\Collection\FloorCollection;
use Stewart\Contracts\Registry\Collection\LabelCollection;
use Stewart\Contracts\Registry\Collection\RegisteredEntityCollection;
use Stewart\Contracts\Registry\Device;
use Stewart\Contracts\Registry\DeviceId;
use Stewart\Contracts\Registry\Floor;
use Stewart\Contracts\Registry\FloorId;
use Stewart\Contracts\Registry\IndexedRegistry;
use Stewart\Contracts\Registry\Label;
use Stewart\Contracts\Registry\LabelId;
use Stewart\Contracts\Registry\RegisteredEntity;
use Stewart\Contracts\Selector\Selector;
use Stewart\Contracts\Service\ServiceResponse;
use Stewart\Contracts\Service\ServiceTarget;
use Stewart\Contracts\State\Collection\EntityStateCollection;
use Stewart\Contracts\State\Collection\StateChangeCollection;
use Stewart\Contracts\State\EntityState;
use Stewart\Contracts\State\EventContext;
use Stewart\Contracts\State\StateChange;
use Stewart\Contracts\Sun\GeoLocation;
use Stewart\Contracts\Time\Duration;
use Stewart\Contracts\Time\Instant;
use Stewart\Contracts\Time\SunOffset;
use Stewart\Contracts\Topic\TopicEvent;
use Stewart\Contracts\Trigger\Collection\HaTriggerCollection;
use Stewart\Contracts\Trigger\HaTrigger;
use Stewart\Contracts\Trigger\TriggerEvent;
use Stewart\Contracts\Trigger\TriggerSpec;
use Stewart\Runtime\Ipc\Collection\WorkerAppCollection;
use Stewart\Runtime\Ipc\Message\AppActivityReport;
use Stewart\Runtime\Ipc\Message\AppFailed;
use Stewart\Runtime\Ipc\Message\Bootstrap;
use Stewart\Runtime\Ipc\Message\EventFired;
use Stewart\Runtime\Ipc\Message\EventFireFailed;
use Stewart\Runtime\Ipc\Message\EventFireRequest;
use Stewart\Runtime\Ipc\Message\EventFireResult;
use Stewart\Runtime\Ipc\Message\ExposedCommandAnswered;
use Stewart\Runtime\Ipc\Message\ExposedEntityCommanded;
use Stewart\Runtime\Ipc\Message\ExposedEntitySynced;
use Stewart\Runtime\Ipc\Message\ExposeEntityRequest;
use Stewart\Runtime\Ipc\Message\ExposeEntityResult;
use Stewart\Runtime\Ipc\Message\ExposureAcknowledged;
use Stewart\Runtime\Ipc\Message\ExposureFailed;
use Stewart\Runtime\Ipc\Message\ExposuresReleased;
use Stewart\Runtime\Ipc\Message\HaConnectionLost;
use Stewart\Runtime\Ipc\Message\HistoryFailed;
use Stewart\Runtime\Ipc\Message\HistoryRequest;
use Stewart\Runtime\Ipc\Message\HistoryResult;
use Stewart\Runtime\Ipc\Message\LogRecord;
use Stewart\Runtime\Ipc\Message\MqttMessageDelivery;
use Stewart\Runtime\Ipc\Message\MqttPublish;
use Stewart\Runtime\Ipc\Message\PausedAppsChanged;
use Stewart\Runtime\Ipc\Message\Ping;
use Stewart\Runtime\Ipc\Message\Pong;
use Stewart\Runtime\Ipc\Message\Publish;
use Stewart\Runtime\Ipc\Message\RegistrySnapshot;
use Stewart\Runtime\Ipc\Message\RemoveExposedEntityRequest;
use Stewart\Runtime\Ipc\Message\ServiceCallFailed;
use Stewart\Runtime\Ipc\Message\ServiceCallRequest;
use Stewart\Runtime\Ipc\Message\ServiceCallResult;
use Stewart\Runtime\Ipc\Message\Shutdown;
use Stewart\Runtime\Ipc\Message\StateChangeBatch;
use Stewart\Runtime\Ipc\Message\StateResynced;
use Stewart\Runtime\Ipc\Message\StateSnapshot;
use Stewart\Runtime\Ipc\Message\Subscribe;
use Stewart\Runtime\Ipc\Message\SubscribeTrigger;
use Stewart\Runtime\Ipc\Message\SubscriptionAck;
use Stewart\Runtime\Ipc\Message\TopicMessage;
use Stewart\Runtime\Ipc\Message\TriggerFired;
use Stewart\Runtime\Ipc\Message\Unsubscribe;
use Stewart\Runtime\Ipc\Message\UpdateExposedEntityRequest;
use Stewart\Runtime\Ipc\Message\WorkerReady;
use Stewart\Runtime\Ipc\StoreSettings;
use Stewart\Runtime\Ipc\Wire\AppIdsFragment;
use Stewart\Runtime\Ipc\Wire\EntityStatesFragment;
use Stewart\Runtime\Ipc\Wire\HistoricalStatesFragment;
use Stewart\Runtime\Ipc\Wire\IpcCodec;
use Stewart\Runtime\Ipc\Wire\RegistryFragment;
use Stewart\Runtime\Ipc\Wire\StateChangesFragment;
use Stewart\Runtime\Ipc\Wire\WorkerAppsFragment;
use Stewart\Runtime\Ipc\WorkerApp;
use Stewart\Runtime\Ipc\WorkerSettings;
use Stewart\Runtime\Lifecycle\AppFailurePhase;
use Stewart\Runtime\Lifecycle\AppState;
use Stewart\Runtime\Model\CorrelationId;
use Stewart\Runtime\Model\ExceptionDetails;
use Stewart\Runtime\Model\LogLevel;
use Stewart\Runtime\Model\ResourceScope;
use Stewart\Runtime\Model\SubscriptionId;
use Stewart\Runtime\Model\SubscriptionKind;
use Stewart\Runtime\Model\WorkerId;
use Stewart\Store\StoreHealth;

final class IpcMessageSamples
{
    private const int AT = 1_758_700_000_123_456;

    private function __construct() {}

    /** @return array<string, IpcMessageSample> */
    public static function listSamplesByTag(): array
    {
        $at = Instant::fromEpochMicroseconds(self::AT);
        $state = new EntityState(new EntityId('light.hall'), 'on', ['brightness' => 254, 'rgb_color' => [255, 170, 80], 'gain' => 1.0], $at, $at, new EventContext('ctx', 'parent', null));
        $demo = ResourceScope::forApp(new AppId('demo'));

        return [
            'bootstrap' => IpcMessageSample::createRoundTrip(self::createBootstrap()),
            'state_snapshot' => new IpcMessageSample(new StateSnapshot(self::createStates($state), 4), new StateSnapshot(self::createStates($state), 4)),
            'registry_snapshot' => new IpcMessageSample(new RegistrySnapshot(self::createRegistry(), 3), new RegistrySnapshot(self::createRegistry(), 3)),
            'state_changes' => new IpcMessageSample(self::createStateChangeBatch($at), self::createStateChangeBatch($at)),
            'state_resynced' => new IpcMessageSample(new StateResynced(self::createStates($state), 9, Duration::seconds(12.5)), new StateResynced(self::createStates($state), 9, Duration::seconds(12.5))),
            'paused_apps_changed' => IpcMessageSample::createRoundTrip(new PausedAppsChanged(self::createAppIds('demo', 'echo'))),
            'ha_connection_lost' => IpcMessageSample::createRoundTrip(new HaConnectionLost($at, 'websocket closed')),
            'event_fired' => IpcMessageSample::createRoundTrip(new EventFired(new HaEvent('zha_event', ['args' => [1, 2], 'params' => ['duration' => 0.5]], EventOrigin::Remote, $at, new EventContext('c')), [new SubscriptionId('w0:1')])),
            'trigger_fired' => IpcMessageSample::createRoundTrip(new TriggerFired(new TriggerEvent(['platform' => 'sun', 'event' => 'sunset', 'offset' => -1800.0, 'id' => 'dusk', 'idx' => '0'], new EventContext('c'), $at), [new SubscriptionId('w0:4')])),
            'topic_message' => IpcMessageSample::createRoundTrip(new TopicMessage(new TopicEvent('presence.home', ['who' => 'resident', 'confidence' => 0.92], new AppId('presence'), $at), [new SubscriptionId('w1:2')])),
            'service_call_result' => IpcMessageSample::createRoundTrip(new ServiceCallResult(new CorrelationId('w0:2'), new ServiceResponse('weather', 'get_forecasts', ['weather.home' => ['forecast' => []]], new EventContext('call-1', null, 'stewart-user')))),
            'service_call_error' => IpcMessageSample::createRoundTrip(ServiceCallFailed::fromException(new CorrelationId('w0:2'), ServiceCallException::rejected('light', 'turn_on', 'Service not found', 'not_found'))),
            'event_fire_result' => IpcMessageSample::createRoundTrip(new EventFireResult(new CorrelationId('w0:6'), new EventContext('fire-1', null, 'stewart-user'))),
            'event_fire_error' => IpcMessageSample::createRoundTrip(EventFireFailed::fromException(new CorrelationId('w0:6'), EventFireException::rejected('doorbell_pressed', 'Unauthorized', 'unauthorized'))),
            'subscription_ack' => IpcMessageSample::createRoundTrip(new SubscriptionAck(new SubscriptionId('w0:1'), false, 'refused')),
            'ping' => IpcMessageSample::createRoundTrip(new Ping(42, $at)),
            'shutdown' => IpcMessageSample::createRoundTrip(new Shutdown('stopping', Duration::seconds(5))),
            'worker_ready' => IpcMessageSample::createRoundTrip(new WorkerReady(self::createAppIds('demo'), self::createAppIds('broken'), 12_345_678)),
            'subscribe_trigger' => IpcMessageSample::createRoundTrip(new SubscribeTrigger(new SubscriptionId('w0:5'), $demo, TriggerSpec::fromSpec(
                HaTriggerCollection::fromTriggers([
                    HaTrigger::onSunset(SunOffset::before(Duration::minutes(30)), 'dusk'),
                    HaTrigger::fromArray(['platform' => 'state', 'entity_id' => ['light.hall'], 'to' => 'on']),
                ]),
                ['room' => 'hall'],
            ))),
            'subscribe' => IpcMessageSample::createRoundTrip(new Subscribe(new SubscriptionId('w0:1'), $demo, SubscriptionKind::Topic, Selector::anyOf(Selector::exact('a.b'), Selector::glob('demo.*'), Selector::regex('/^x/'), Selector::mqttFilter('home/+/#'), Selector::any()))),
            'unsubscribe' => IpcMessageSample::createRoundTrip(new Unsubscribe(new SubscriptionId('w0:1'))),
            'history_request' => IpcMessageSample::createRoundTrip(new HistoryRequest(new CorrelationId('w0:3'), $demo, new EntityId('light.hall'), new HistoryWindow($at, $at->plus(Duration::minutes(30))), HistoryDetail::AllChanges)),
            'history_result' => new IpcMessageSample(self::createHistoryResult($at), self::createHistoryResult($at)),
            'history_error' => IpcMessageSample::createRoundTrip(HistoryFailed::fromException(new CorrelationId('w0:3'), HistoryException::recorderUnavailable(new EntityId('light.hall')))),
            'service_call_request' => IpcMessageSample::createRoundTrip(new ServiceCallRequest(new CorrelationId('w0:2'), $demo, 'light', 'turn_on', ['transition' => 1.5], new ServiceTarget(entityIds: [new EntityId('light.hall')], areaIds: ['hall']), false)),
            'event_fire_request' => IpcMessageSample::createRoundTrip(new EventFireRequest(new CorrelationId('w0:6'), $demo, 'doorbell_pressed', ['button' => 'front', 'pressure' => 0.8, 'note' => null])),
            'publish' => IpcMessageSample::createRoundTrip(new Publish('demo.triggered', ['state' => 'on', 'nested' => [1.0, null]], $demo, $at)),
            'log_record' => IpcMessageSample::createRoundTrip(new LogRecord($demo, LogLevel::Warning, 'Something odd', ['to' => 'on', 'count' => 3], null)),
            'app_failed' => IpcMessageSample::createRoundTrip(new AppFailed($demo, AppFailurePhase::Handler, 'RuntimeException', 'boom', '#0 {main}', 'subscription w0:1', 100, new ExceptionDetails('Stewart\Contracts\Exception\StateException', 'entity_not_found', ['entityId' => 'light.hall', 'known' => ['a', null]]))),
            'mqtt_publish' => IpcMessageSample::createRoundTrip(new MqttPublish(new MqttMessage('home/hall/light', "on\xff", MqttQos::AtLeastOnce, true), $demo)),
            'mqtt_message' => IpcMessageSample::createRoundTrip(new MqttMessageDelivery(new MqttMessage('home/hall/temp', '{"t":21.5}'), [new SubscriptionId('w0:3')])),
            'expose_entity_request' => IpcMessageSample::createRoundTrip(new ExposeEntityRequest(
                new CorrelationId('w0:7'),
                $demo,
                new ExposedEntityKey('soil_moisture'),
                new SensorConfig(SensorDeviceClass::Moisture, '%', SensorStateClass::Measurement, 1, name: 'Soil moisture', icon: 'mdi:sprout'),
                new DeviceInfo('greenhouse', 'Greenhouse', model: 'Probe v2'),
                new ExposedStateChange(new ExposedState(null), ['source' => 'probe'], true),
            )),
            'expose_entity_result' => IpcMessageSample::createRoundTrip(new ExposeEntityResult(new CorrelationId('w0:7'), self::createExposedSnapshot())),
            'update_exposed_entity_request' => IpcMessageSample::createRoundTrip(new UpdateExposedEntityRequest(new CorrelationId('w0:8'), $demo, new ExposedEntityKey('soil_moisture'), new ExposedStateChange(new ExposedState(38)))),
            'remove_exposed_entity_request' => IpcMessageSample::createRoundTrip(new RemoveExposedEntityRequest(new CorrelationId('w0:9'), $demo, new ExposedEntityKey('soil_moisture'))),
            'exposure_acknowledged' => IpcMessageSample::createRoundTrip(new ExposureAcknowledged(new CorrelationId('w0:8'))),
            'exposure_error' => IpcMessageSample::createRoundTrip(ExposureFailed::fromException(new CorrelationId('w0:7'), ExposureException::configInvalid('The sensor unit does not fit.'))),
            'exposures_released' => IpcMessageSample::createRoundTrip(new ExposuresReleased($demo)),
            'exposed_entity_commanded' => IpcMessageSample::createRoundTrip(new ExposedEntityCommanded(
                '3f2b9c0e8d7a4f61',
                $demo,
                new ExposedEntityKey('night_mode'),
                new SwitchCommand(SwitchAction::TurnOn, new EventContext('01J9ZK3YQ8T7C4M2N6P5R0VWXA', null, 'user-1')),
            )),
            'exposed_command_answered' => IpcMessageSample::createRoundTrip(new ExposedCommandAnswered('3f2b9c0e8d7a4f61', false, 'Night mode cannot start while the alarm is armed.')),
            'exposed_entity_synced' => IpcMessageSample::createRoundTrip(new ExposedEntitySynced($demo, new ExposedEntityKey('soil_moisture'), self::createExposedSnapshot())),
            'pong' => IpcMessageSample::createRoundTrip(new Pong(42, Duration::microseconds(1_250), 12_345_678, [new AppActivityReport($demo, AppState::Running, 2, 1, 30, 1, 4, 2, 3, 5)], new StoreHealth(false, 'timed out', Instant::fromEpochMicroseconds(1_700_000_000_000_000)))),
        ];
    }

    private static function createExposedSnapshot(): ExposedEntitySnapshot
    {
        return new ExposedEntitySnapshot(new EntityId('sensor.greenhouse_soil_moisture'), new ExposedState(38), ['source' => 'probe'], true);
    }

    private static function createHistoryResult(Instant $at): HistoryResult
    {
        return new HistoryResult(new CorrelationId('w0:3'), HistoricalStatesFragment::fromCollection(HistoricalStateCollection::fromStates([
            new EntityState(new EntityId('light.hall'), 'off', lastChangedAt: $at, lastUpdatedAt: $at),
            new EntityState(new EntityId('light.hall'), 'on', ['brightness' => 254], $at->plus(Duration::minutes(5)), $at->plus(Duration::minutes(5))),
        ])));
    }

    public static function createBootstrap(): Bootstrap
    {
        return new Bootstrap(
            protocol: IpcCodec::PROTOCOL_VERSION,
            workerId: new WorkerId(1),
            timeZone: 'Europe/Budapest',
            location: new GeoLocation(47.4979, 19.0402, 96.0),
            apps: WorkerAppsFragment::fromCollection(WorkerAppCollection::fromApps([new WorkerApp(new AppId('demo'), 'Stewart\Runtime\Tests\Fixtures\Apps\Demo', ['watch' => 'input_boolean.hall']), new WorkerApp(new AppId('echo'), 'Stewart\Runtime\Tests\Fixtures\Apps\Relay', [])])),
            settings: new WorkerSettings(LogLevel::Info, Duration::seconds(35), Duration::seconds(5), 100, Duration::seconds(60), '/app/services.php', 'Stewart\Generated', true),
            store: new StoreSettings('redis://:not-a-secret@valkey:6379/0', 'stewart', Duration::seconds(5), Duration::seconds(5)),
            knownAppIds: self::createAppIds('demo', 'echo'),
            pausedAppIds: self::createAppIds('echo'),
            haUserId: 'stewart-user',
        );
    }

    // A fragment remembers its encoding, so the sent and the expected message must not share one.
    private static function createStates(EntityState $state): EntityStatesFragment
    {
        return EntityStatesFragment::fromCollection(EntityStateCollection::keyedByEntityId([$state]));
    }

    private static function createRegistry(): RegistryFragment
    {
        $kitchen = new AreaId('kitchen');
        $bulb = new DeviceId('bulb');
        $night = new LabelId('night');

        return RegistryFragment::fromRegistry(IndexedRegistry::fromParts(
            AreaCollection::keyedByAreaId([new Area($kitchen, 'Kitchen', new FloorId('ground'), ['Cooking'], [$night], 'mdi:stove')]),
            FloorCollection::keyedByFloorId([new Floor(new FloorId('ground'), 'Ground', 0)]),
            LabelCollection::keyedByLabelId([new Label($night, 'Night', 'indigo')]),
            DeviceCollection::keyedByDeviceId([new Device($bulb, 'Bulb', 'Ceiling', $kitchen, [], 'Signify', 'LCA001')]),
            RegisteredEntityCollection::keyedByEntityId([new RegisteredEntity(new EntityId('light.ceiling'), $bulb, null, [$night], 'Ceiling', null, null, null)]),
        ));
    }

    private static function createStateChangeBatch(Instant $at): StateChangeBatch
    {
        $change = new StateChange(
            new EntityId('light.hall'),
            new EntityState(new EntityId('light.hall'), 'off', [], $at, $at),
            new EntityState(new EntityId('light.hall'), 'on', ['brightness' => 254, 'gain' => 1.0], $at, $at, new EventContext('ctx', null, 'user')),
            $at,
            new EventContext('ctx'),
        );

        return new StateChangeBatch(StateChangesFragment::fromCollection(StateChangeCollection::fromChanges([$change])));
    }

    private static function createAppIds(string ...$ids): AppIdsFragment
    {
        return AppIdsFragment::fromCollection(AppIdCollection::fromIds(array_map(static fn(string $id): AppId => new AppId($id), $ids)));
    }
}
