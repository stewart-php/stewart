<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Integration\Protocol;

use Closure;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\Event\HaEvent;
use Stewart\Contracts\Exception\MqttError;
use Stewart\Contracts\Exception\MqttException;
use Stewart\Contracts\Exception\ServiceCallException;
use Stewart\Contracts\Exception\TopicException;
use Stewart\Contracts\Mqtt\MqttMessage;
use Stewart\Contracts\Service\ServiceResponse;
use Stewart\Contracts\State\Collection\EntityStateCollection;
use Stewart\Contracts\State\EntityState;
use Stewart\Contracts\State\StateChange;
use Stewart\Contracts\Time\Duration;
use Stewart\Contracts\Topic\TopicEvent;
use Stewart\Runtime\Ipc\Message\AppFailed;
use Stewart\Runtime\Ipc\Message\EventFired;
use Stewart\Runtime\Ipc\Message\HaConnectionLost;
use Stewart\Runtime\Ipc\Message\LogRecord;
use Stewart\Runtime\Ipc\Message\MqttMessageDelivery;
use Stewart\Runtime\Ipc\Message\MqttPublish;
use Stewart\Runtime\Ipc\Message\Ping;
use Stewart\Runtime\Ipc\Message\Pong;
use Stewart\Runtime\Ipc\Message\Publish;
use Stewart\Runtime\Ipc\Message\ServiceCallFailed;
use Stewart\Runtime\Ipc\Message\ServiceCallRequest;
use Stewart\Runtime\Ipc\Message\ServiceCallResult;
use Stewart\Runtime\Ipc\Message\Shutdown;
use Stewart\Runtime\Ipc\Message\StateResynced;
use Stewart\Runtime\Ipc\Message\Subscribe;
use Stewart\Runtime\Ipc\Message\TopicMessage;
use Stewart\Runtime\Ipc\Message\WorkerReady;
use Stewart\Runtime\Ipc\StoreSettings;
use Stewart\Runtime\Ipc\Wire\EntityStatesFragment;
use Stewart\Runtime\Ipc\Wire\IpcCodec;
use Stewart\Runtime\Ipc\WorkerApp;
use Stewart\Runtime\Lifecycle\AppFailurePhase;
use Stewart\Runtime\Lifecycle\AppState;
use Stewart\Runtime\Model\SubscriptionKind;
use Stewart\Runtime\Tests\Fixtures\Ipc\TestBootstrap;
use Stewart\Runtime\Tests\Fixtures\Protocol\ConstructCaller;
use Stewart\Runtime\Tests\Fixtures\Protocol\DisposeCaller;
use Stewart\Runtime\Tests\Fixtures\Protocol\EdgeWatcher;
use Stewart\Runtime\Tests\Fixtures\Protocol\EventLogger;
use Stewart\Runtime\Tests\Fixtures\Protocol\InitCaller;
use Stewart\Runtime\Tests\Fixtures\Protocol\InMemoryStoreBackendOpener;
use Stewart\Runtime\Tests\Fixtures\Protocol\MqttRelay;
use Stewart\Runtime\Tests\Fixtures\Protocol\OperatorChain;
use Stewart\Runtime\Tests\Fixtures\Protocol\OutageWatcher;
use Stewart\Runtime\Tests\Fixtures\Protocol\PayloadPublisher;
use Stewart\Runtime\Tests\Fixtures\Protocol\RunCounter;
use Stewart\Runtime\Tests\Fixtures\Protocol\Scheduled;
use Stewart\Runtime\Tests\Fixtures\Protocol\SerialHandler;
use Stewart\Runtime\Tests\Fixtures\Protocol\SlowStarter;
use Stewart\Runtime\Tests\Fixtures\Protocol\SubscribesThenCalls;
use Stewart\Runtime\Tests\Fixtures\Protocol\TopicEcho;
use Stewart\Runtime\Tests\Fixtures\Protocol\WorkerHarness;
use Stewart\Runtime\Tests\Fixtures\Worker\InMemoryWorkerSpawner;
use Stewart\Runtime\Time\SystemClock;
use Stewart\Testing\Store\InMemoryStoreBackend;

#[CoversNothing]
final class WorkerProtocolTest extends TestCase
{
    private const string WATCHED = 'input_boolean.protocol_test';

    private const string LIGHT = 'light.protocol_test';

    private ?WorkerHarness $worker = null;

    private InMemoryStoreBackend $store;

    protected function setUp(): void
    {
        $this->store = new InMemoryStoreBackend(SystemClock::inUtc());
    }

    protected function tearDown(): void
    {
        $this->worker?->close();
        $this->worker = null;
    }

    public function testWorkerRunsTheFullProtocol(): void
    {
        $this->start([
            new WorkerApp(id: new AppId('edge-watcher'), class: EdgeWatcher::class, options: ['watch' => self::WATCHED]),
            new WorkerApp(id: new AppId('topic-echo'), class: TopicEcho::class, options: []),
            new WorkerApp(id: new AppId('event-logger'), class: EventLogger::class, options: []),
        ], timeZone: 'Europe/Budapest');

        $subscriptions = [];
        $logs = [];
        $ready = $this->receiveUntil(WorkerReady::class, collect: function (object $message) use (&$subscriptions, &$logs): void {
            if ($message instanceof Subscribe) {
                $subscriptions[] = $message;
            } elseif ($message instanceof LogRecord) {
                $logs[] = $message;
            }
        });

        self::assertSame([], $ready->failedAppIds->collection->toStrings());
        self::assertEqualsCanonicalizing(['edge-watcher', 'topic-echo', 'event-logger'], $ready->appIds->collection->toStrings());
        self::assertCount(2, $subscriptions, 'State subscriptions are matched by the worker and never announced.');

        $byKey = [];

        foreach ($subscriptions as $subscription) {
            $byKey[$subscription->selector->toCanonicalKey()] = $subscription;
        }

        self::assertSame(SubscriptionKind::Topic, $byKey['glob:' . TopicEcho::PATTERN]->kind);
        self::assertSame(SubscriptionKind::Event, $byKey['exact:' . EventLogger::EVENT]->kind);

        $startup = self::findLog($logs, 'Watcher starting');
        self::assertNotNull($startup);
        self::assertSame('edge-watcher', $startup->scope->wireValue());
        self::assertSame('off', $startup->context['current_state'] ?? null);
        self::assertSame(4, $startup->context['entities_visible'] ?? null);

        $this->sendToggle();
        $publish = $this->receiveUntil(Publish::class);

        self::assertSame(EdgeWatcher::TOPIC, $publish->topic);
        self::assertSame('edge-watcher', $publish->publisherScope->wireValue());
        self::assertIsArray($publish->payload);
        self::assertSame('on', $publish->payload['state'] ?? null);

        $this->send(new TopicMessage(
            event: new TopicEvent($publish->topic, $publish->payload, $publish->publisherScope->appId, $publish->publishedAt),
            deliverTo: [$byKey['glob:' . TopicEcho::PATTERN]->subscriptionId],
        ));

        $echoLog = $this->receiveUntil(LogRecord::class, static fn(LogRecord $log): bool => $log->message === 'Topic received');
        self::assertSame('topic-echo', $echoLog->scope->wireValue());
        self::assertSame(EdgeWatcher::TOPIC, $echoLog->context['topic'] ?? null);
        self::assertSame('edge-watcher', $echoLog->context['from'] ?? null);

        $this->send(new EventFired(
            event: new HaEvent(EventLogger::EVENT, ['pressed' => 'single']),
            deliverTo: [$byKey['exact:' . EventLogger::EVENT]->subscriptionId],
        ));

        $eventLog = $this->receiveUntil(LogRecord::class, static fn(LogRecord $log): bool => $log->message === 'Event received');

        self::assertSame('event-logger', $eventLog->scope->wireValue());
        self::assertSame(EventLogger::EVENT, $eventLog->context['event_type'] ?? null);
        self::assertSame('{"pressed":"single"}', $eventLog->context['data'] ?? null);

        $this->send(new Ping(nonce: 42, sentAt: SystemClock::inUtc()->getNow()));
        $pong = $this->receiveUntil(Pong::class);

        self::assertSame(42, $pong->nonce);
        self::assertGreaterThan(0, $pong->memoryBytes);

        $summary = $this->shutDown('test complete');

        self::assertStringContainsString('worker 0 stopped', $summary);
        self::assertStringContainsString('test complete', $summary);
    }

    public function testStateChangeReachesLocalSubscriptionAfterReady(): void
    {
        $this->start([new WorkerApp(id: new AppId('edge-watcher'), class: EdgeWatcher::class, options: ['watch' => self::WATCHED])]);

        $announced = [];
        $this->receiveUntil(WorkerReady::class, collect: static function (object $message) use (&$announced): void {
            if ($message instanceof Subscribe) {
                $announced[] = $message->kind;
            }
        });

        self::assertNotContains(SubscriptionKind::StateChange, $announced);

        $this->sendToggle();
        $delivered = $this->receiveUntil(LogRecord::class, static fn(LogRecord $log): bool => $log->message === 'Watched entity changed');

        self::assertSame('on', $delivered->context['to'] ?? null);

        $this->shutDown('done');
    }

    public function testOperatorChainRoutesAndWorkerExits(): void
    {
        $this->start([new WorkerApp(id: new AppId('operator-chain'), class: OperatorChain::class, options: [])]);

        $announced = 0;
        $ready = $this->receiveUntil(WorkerReady::class, collect: static function (object $message) use (&$announced): void {
            if ($message instanceof Subscribe) {
                ++$announced;
            }
        });

        self::assertSame([], $ready->failedAppIds->collection->toStrings());
        self::assertSame(0, $announced, 'Operator chains over state run entirely worker-side.');

        $this->sendToggle();
        $publish = $this->receiveUntil(Publish::class);

        self::assertSame('operator.settled', $publish->topic);
        self::assertIsArray($publish->payload);
        self::assertSame('on', $publish->payload['state'] ?? null);

        $this->sendChanges(self::createChange('sensor.hall_lux', '10', '20'));
        $this->send(new Ping(nonce: 7, sentAt: SystemClock::inUtc()->getNow()));
        self::assertSame(7, $this->receiveUntil(Pong::class)->nonce);

        $summary = $this->shutDown('operators done');

        self::assertStringContainsString('worker 0 stopped', $summary);
    }

    public function testPongCarriesActivityReportPerApp(): void
    {
        $this->start([
            new WorkerApp(id: new AppId('operator-chain'), class: OperatorChain::class, options: []),
            new WorkerApp(id: new AppId('serial-handler'), class: SerialHandler::class, options: []),
        ]);

        $ready = $this->receiveUntil(WorkerReady::class);

        self::assertSame([], $ready->failedAppIds->collection->toStrings());

        $this->sendToggle();
        $settled = $this->receiveUntil(Publish::class);
        self::assertSame('operator.settled', $settled->topic);

        $this->send(new Ping(nonce: 5, sentAt: SystemClock::inUtc()->getNow()));
        $pong = $this->receiveUntil(Pong::class);

        self::assertSame(5, $pong->nonce);
        self::assertGreaterThan(0, $pong->memoryBytes);

        $apps = [];

        foreach ($pong->apps as $report) {
            $apps[$report->scope->wireValue()] = $report;
        }

        self::assertSame(['operator-chain', 'serial-handler'], array_keys($apps));
        self::assertSame(AppState::Running, $apps['operator-chain']->state);
        self::assertSame(AppState::Running, $apps['serial-handler']->state);
        self::assertGreaterThanOrEqual(1, $apps['operator-chain']->delivered);
        self::assertSame(1, $apps['operator-chain']->publishes);
        self::assertSame(0, $apps['operator-chain']->subscriptionDropped);
        self::assertGreaterThanOrEqual(2, $apps['operator-chain']->subscriptions);
        self::assertSame(1, $apps['serial-handler']->subscriptions);
        self::assertSame(0, $apps['serial-handler']->delivered);

        $this->shutDown('done');
    }

    public function testPongCountsTheArmedSchedules(): void
    {
        $this->start([new WorkerApp(id: new AppId('scheduled'), class: Scheduled::class, options: [])], timeZone: 'Europe/Budapest');
        $this->receiveUntil(WorkerReady::class);

        $this->send(new Ping(nonce: 1, sentAt: SystemClock::inUtc()->getNow()));

        self::assertSame(2, $this->receiveUntil(Pong::class)->apps[0]->schedules);
    }

    public function testScheduleFiresAndWorkerExits(): void
    {
        $this->start([new WorkerApp(id: new AppId('scheduled'), class: Scheduled::class, options: [])], timeZone: 'Europe/Budapest');

        $ready = $this->receiveUntil(WorkerReady::class);

        self::assertSame([], $ready->failedAppIds->collection->toStrings());

        $publish = $this->receiveUntil(Publish::class);

        self::assertSame('schedule.fired', $publish->topic);
        self::assertIsArray($publish->payload);
        $scheduledFor = $publish->payload['scheduled_for'] ?? null;

        self::assertIsString($scheduledFor);
        self::assertStringEndsNotWith(
            '+00:00',
            $scheduledFor,
            'The container runs on UTC; an occurrence carries Home Assistant\'s offset instead.',
        );

        $again = $this->receiveUntil(Publish::class);

        self::assertSame('schedule.fired', $again->topic);
        self::assertIsArray($again->payload);
        self::assertSame(
            $publish->payload['task'] ?? null,
            $again->payload['task'] ?? null,
            'The same schedule re-arms itself rather than firing once.',
        );
        self::assertNotSame($publish->payload['scheduled_for'] ?? null, $again->payload['scheduled_for'] ?? null);

        $summary = $this->shutDown('schedules done');

        self::assertStringContainsString('worker 0 stopped', $summary);
    }

    public function testConstructorScheduleWaitsForInitialize(): void
    {
        $this->start([new WorkerApp(id: new AppId('slow-starter'), class: SlowStarter::class, options: [])]);

        $topicsBefore = [];

        $this->receiveUntil(
            Publish::class,
            static fn(Publish $publish): bool => $publish->topic === 'schedule.fired',
            static function (object $message) use (&$topicsBefore): void {
                if ($message instanceof Publish) {
                    $topicsBefore[] = $message->topic;
                }
            },
        );

        self::assertSame(['init.done'], $topicsBefore, 'Due 10ms after arming, but the app was still initializing.');

        $summary = $this->shutDown('schedule test done');

        self::assertStringContainsString('worker 0 stopped', $summary);
    }

    public function testServiceCallInsideInitializeGetsItsAnswer(): void
    {
        $this->start([new WorkerApp(id: new AppId('init-caller'), class: InitCaller::class, options: [])], callTimeout: 5.0);

        $request = $this->receiveUntil(ServiceCallRequest::class);
        $this->send(new ServiceCallResult($request->correlationId, new ServiceResponse('light', 'turn_on')));

        $log = $this->receiveUntil(LogRecord::class, static fn(LogRecord $log): bool => $log->message === 'Initialize call finished');
        $ready = $this->receiveUntil(WorkerReady::class);

        self::assertSame('light.turn_on', $log->context['service'] ?? null);
        self::assertSame(['init-caller'], $ready->appIds->collection->toStrings());

        $this->shutDown('done');
    }

    public function testServiceCallInsideAConstructorGetsItsAnswer(): void
    {
        $this->start([new WorkerApp(id: new AppId('construct-caller'), class: ConstructCaller::class, options: [])], callTimeout: 5.0);

        $request = $this->receiveUntil(ServiceCallRequest::class);
        $this->send(new ServiceCallResult($request->correlationId, new ServiceResponse('light', 'turn_on')));

        $log = $this->receiveUntil(LogRecord::class, static fn(LogRecord $log): bool => $log->message === 'Construct call finished');
        $ready = $this->receiveUntil(WorkerReady::class);

        self::assertSame('light.turn_on', $log->context['service'] ?? null);
        self::assertSame(['construct-caller'], $ready->appIds->collection->toStrings());

        $this->shutDown('done');
    }

    public function testEventDuringInitializeWaitsForItToReturn(): void
    {
        $this->start([new WorkerApp(id: new AppId('subscribes-then-calls'), class: SubscribesThenCalls::class, options: [])], callTimeout: 5.0);

        $request = $this->receiveUntil(ServiceCallRequest::class);

        $this->sendToggle();
        $this->send(new ServiceCallResult($request->correlationId, new ServiceResponse('light', 'turn_on')));

        $order = [];
        $this->receiveUntil(
            LogRecord::class,
            static fn(LogRecord $log): bool => $log->message === 'Handled',
            static function (object $message) use (&$order): void {
                if ($message instanceof LogRecord && $message->message === 'Initialize returned') {
                    $order[] = $message->message;
                }
            },
        );

        self::assertSame(['Initialize returned'], $order, 'The handler runs only after initialize() returned.');

        $this->shutDown('done');
    }

    public function testDisposeCanCallAServiceWithinTheGracePeriod(): void
    {
        $this->start([new WorkerApp(id: new AppId('dispose-caller'), class: DisposeCaller::class, options: [])]);
        $this->receiveUntil(WorkerReady::class);

        $this->send(new Shutdown('stopping', Duration::seconds(5.0)));

        $request = $this->receiveUntil(ServiceCallRequest::class);
        self::assertSame('turn_off', $request->service);

        $this->send(new ServiceCallResult($request->correlationId, new ServiceResponse('light', 'turn_off')));
        $this->receiveUntil(LogRecord::class, static fn(LogRecord $log): bool => $log->message === 'Dispose call finished');

        self::assertStringContainsString('0 calls abandoned', $this->getWorkerHarness()->join());
        $this->worker = null;
    }

    public function testSubscriptionHandlesOneEventAtATime(): void
    {
        $this->start([new WorkerApp(id: new AppId('serial-handler'), class: SerialHandler::class, options: [])]);
        $this->receiveUntil(WorkerReady::class);

        $this->sendChanges(self::createChange(SerialHandler::ENTITY, '1', '2'), self::createChange(SerialHandler::ENTITY, '2', '3'));

        $first = $this->receiveUntil(ServiceCallRequest::class);
        self::assertSame(['value' => '2'], $first->data);

        $this->send(new Ping(nonce: 1, sentAt: SystemClock::inUtc()->getNow()));
        $this->receiveUntil(Pong::class, collect: static function (object $message): void {
            self::assertNotInstanceOf(ServiceCallRequest::class, $message, 'The second event must wait for the first handler.');
        });

        $this->send(new ServiceCallResult($first->correlationId, new ServiceResponse('script', 'record')));
        self::assertSame('2', $this->receiveUntil(LogRecord::class, static fn(LogRecord $log): bool => $log->message === 'Handled')->context['value'] ?? null);

        $second = $this->receiveUntil(ServiceCallRequest::class);
        self::assertSame(['value' => '3'], $second->data);

        $this->send(new ServiceCallResult($second->correlationId, new ServiceResponse('script', 'record')));
        self::assertSame('3', $this->receiveUntil(LogRecord::class, static fn(LogRecord $log): bool => $log->message === 'Handled')->context['value'] ?? null);

        $this->shutDown('done');
    }

    public function testRejectedCallFailsTheHandlerWithTheReason(): void
    {
        $this->start([new WorkerApp(id: new AppId('serial-handler'), class: SerialHandler::class, options: [])]);
        $this->receiveUntil(WorkerReady::class);

        $this->sendChanges(self::createChange(SerialHandler::ENTITY, '1', '2'));
        $request = $this->receiveUntil(ServiceCallRequest::class);

        $this->send(ServiceCallFailed::fromException($request->correlationId, ServiceCallException::rejected('script', 'record', 'Service not found', 'not_found')));
        $failure = $this->receiveUntil(AppFailed::class);

        self::assertSame(AppFailurePhase::Handler, $failure->phase);
        self::assertSame(ServiceCallException::class, $failure->class);
        self::assertStringContainsString('script.record was rejected by Home Assistant: Service not found (not_found)', $failure->message);
        self::assertNotSame('', $failure->trace);

        $this->shutDown('done');
    }

    public function testUnansweredCallTimesOut(): void
    {
        $this->start(
            [new WorkerApp(id: new AppId('edge-watcher'), class: EdgeWatcher::class, options: ['watch' => self::WATCHED, 'light' => self::LIGHT])],
            callTimeout: 0.5,
        );

        $this->receiveUntil(WorkerReady::class);
        $this->sendToggle();

        $request = $this->receiveUntil(ServiceCallRequest::class);
        self::assertSame([self::LIGHT], array_map(strval(...), $request->target->entityIds ?? []));

        $completion = $this->receiveUntil(LogRecord::class, static fn(LogRecord $log): bool => $log->message === 'Service call finished');
        $error = $completion->context['exception'] ?? null;

        self::assertFalse($completion->context['success'] ?? null);
        self::assertIsString($error);
        self::assertStringContainsString('timed out', $error);
        self::assertStringContainsString('500ms', $error);

        $this->shutDown('done');
    }

    public function testInvalidTopicPayloadFailsThePublisher(): void
    {
        $this->start([new WorkerApp(id: new AppId('payload-publisher'), class: PayloadPublisher::class, options: [])]);

        $failure = $this->receiveUntil(AppFailed::class);
        $ready = $this->receiveUntil(WorkerReady::class);

        self::assertSame(AppFailurePhase::Initialize, $failure->phase);
        self::assertSame(TopicException::class, $failure->class);
        self::assertStringContainsString('payload[nested][0]', $failure->message);
        self::assertSame(['payload-publisher'], $ready->failedAppIds->collection->toStrings());

        $this->shutDown('done');
    }

    public function testMqttAppIsRefusedWhileMqttIsUnset(): void
    {
        $this->start([new WorkerApp(id: new AppId('mqtt-relay'), class: MqttRelay::class, options: [])]);

        $failure = $this->receiveUntil(AppFailed::class);
        $ready = $this->receiveUntil(WorkerReady::class);

        self::assertSame(MqttException::class, $failure->class);
        self::assertSame(MqttError::NotConfigured->value, $failure->details?->reason);
        self::assertSame(['mqtt-relay'], $ready->failedAppIds->collection->toStrings());

        $this->shutDown('done');
    }

    public function testMqttMessageReachesTheWatchingApp(): void
    {
        $this->start([new WorkerApp(id: new AppId('mqtt-relay'), class: MqttRelay::class, options: [])], mqttEnabled: true);

        $subscribe = $this->receiveUntil(Subscribe::class);
        $this->receiveUntil(WorkerReady::class);

        self::assertSame(SubscriptionKind::Mqtt, $subscribe->kind);

        $this->send(new MqttMessageDelivery(new MqttMessage('home/hall/temp', '21.5'), [$subscribe->subscriptionId]));
        $publish = $this->receiveUntil(MqttPublish::class);

        self::assertSame(MqttRelay::RELAY_TOPIC, $publish->message->topic);
        self::assertSame('mqtt-relay', $publish->publisherScope->wireValue());

        $this->shutDown('done');
    }

    public function testResyncReplaysChangesAfterOutage(): void
    {
        $this->start([
            new WorkerApp(id: new AppId('edge-watcher'), class: EdgeWatcher::class, options: ['watch' => self::WATCHED, 'light' => self::LIGHT]),
            new WorkerApp(id: new AppId('outage-watcher'), class: OutageWatcher::class, options: []),
        ]);

        $this->receiveUntil(WorkerReady::class);

        $this->send(new HaConnectionLost(SystemClock::inUtc()->getNow(), 'websocket closed'));
        $lost = $this->receiveUntil(LogRecord::class, static fn(LogRecord $log): bool => $log->message === 'Home Assistant is gone');
        self::assertSame('websocket closed', $lost->context['reason'] ?? null);

        $requests = 0;
        $this->sendToggle();
        $call = $this->receiveUntil(
            LogRecord::class,
            static fn(LogRecord $log): bool => $log->message === 'Service call finished',
            static function (object $message) use (&$requests): void {
                if ($message instanceof ServiceCallRequest) {
                    ++$requests;
                }
            },
        );

        self::assertFalse($call->context['success'] ?? null);
        self::assertIsString($call->context['exception'] ?? null);
        self::assertStringContainsString('Home Assistant is disconnected', $call->context['exception']);
        self::assertSame(0, $requests, 'A call during an outage fails in the worker, without a round trip.');

        $this->sendChanges(self::createChange(self::WATCHED, 'on', 'off'));
        $this->send(new StateResynced(states: self::createResyncSnapshot(), revision: 9, outage: Duration::seconds(12.5)));

        $announced = 0;
        $resync = $this->receiveUntil(
            LogRecord::class,
            static fn(LogRecord $log): bool => $log->message === 'State resynced',
            static function (object $message) use (&$announced): void {
                if ($message instanceof Subscribe || $message instanceof WorkerReady) {
                    ++$announced;
                }
            },
        );

        self::assertSame(0, $announced, 'A resync must not re-run initialize().');
        self::assertSame(2, $resync->context['entities'] ?? null);
        self::assertSame('12s 500ms', $resync->context['outage'] ?? null);
        self::assertSame(4, $resync->context['reconstructed'] ?? null);

        $replayed = $this->receiveUntil(LogRecord::class, static fn(LogRecord $log): bool => $log->message === 'Watched entity changed');
        $request = $this->receiveUntil(ServiceCallRequest::class);

        self::assertSame('on', $replayed->context['to'] ?? null, 'The off→on edge that happened during the outage reaches whenChangedTo().');
        self::assertSame([self::LIGHT], array_map(strval(...), $request->target->entityIds ?? []));

        $this->send(new ServiceCallResult($request->correlationId, new ServiceResponse('light', 'turn_on')));
        $this->receiveUntil(Publish::class);

        $this->shutDown('done');
    }

    public function testStaleResyncStillAnnouncesTheEndOfTheOutage(): void
    {
        $this->start([new WorkerApp(id: new AppId('outage-watcher'), class: OutageWatcher::class, options: [])]);

        $this->receiveUntil(WorkerReady::class);

        $this->send(new HaConnectionLost(SystemClock::inUtc()->getNow(), 'websocket closed'));
        $this->receiveUntil(LogRecord::class, static fn(LogRecord $log): bool => $log->message === 'Home Assistant is gone');

        $this->send(new StateResynced(states: self::createResyncSnapshot(), revision: 1, outage: Duration::seconds(3)));
        $restored = $this->receiveUntil(LogRecord::class, static fn(LogRecord $log): bool => $log->message === 'State resynced');

        self::assertSame(4, $restored->context['entities'] ?? null, 'A stale resync leaves the state cache as it was.');
        self::assertSame('3s', $restored->context['outage'] ?? null);
        self::assertSame(0, $restored->context['reconstructed'] ?? null);

        $this->shutDown('done');
    }

    public function testStoredValueOutlivesWorker(): void
    {
        $apps = [new WorkerApp(id: new AppId('run-counter'), class: RunCounter::class, options: ['watch' => self::WATCHED])];

        $this->start($apps);
        $first = $this->receiveUntil(LogRecord::class, static fn(LogRecord $log): bool => $log->message === 'Run counter starting');

        self::assertSame(1, $first->context['run'] ?? null);
        self::assertSame(0, $first->context['changes_seen_before'] ?? null);

        $this->receiveUntil(WorkerReady::class);
        $this->sendToggle();
        $this->receiveUntil(LogRecord::class, static fn(LogRecord $log): bool => $log->message === 'Change counted');
        $this->shutDown('restarting');

        $this->start($apps);
        $second = $this->receiveUntil(LogRecord::class, static fn(LogRecord $log): bool => $log->message === 'Run counter starting');

        self::assertSame(2, $second->context['run'] ?? null, 'The next worker reads what the previous one left.');
        self::assertSame(1, $second->context['changes_seen_before'] ?? null);

        $this->shutDown('done');
    }

    public function testPongCarriesWhatTheWorkerKnowsOfTheStore(): void
    {
        $this->start([new WorkerApp(id: new AppId('run-counter'), class: RunCounter::class, options: ['watch' => self::WATCHED])]);
        $this->receiveUntil(WorkerReady::class);

        $this->send(new Ping(nonce: 1, sentAt: SystemClock::inUtc()->getNow()));
        self::assertTrue($this->receiveUntil(Pong::class)->store?->available);

        $this->store->simulateOutage('connection refused');
        $this->sendToggle();
        $this->receiveUntil(AppFailed::class);
        $this->send(new Ping(nonce: 2, sentAt: SystemClock::inUtc()->getNow()));
        $pong = $this->receiveUntil(Pong::class);

        self::assertFalse($pong->store?->available);
        self::assertStringEndsWith('connection refused', (string) $pong->store?->lastFailure);

        $this->shutDown('done');
    }

    /** @param list<WorkerApp> $apps */
    private function start(array $apps, float $callTimeout = 30.0, string $timeZone = 'UTC', bool $mqttEnabled = false): void
    {
        $spawner = new InMemoryWorkerSpawner(IpcCodec::createForWorkerBootstrap(), new InMemoryStoreBackendOpener($this->store, SystemClock::inUtc()));

        $this->worker = WorkerHarness::boot(
            $spawner,
            TestBootstrap::createForApps(
                $apps,
                callTimeout: Duration::seconds($callTimeout),
                timeZone: $timeZone,
                store: new StoreSettings('memory://', 'protocol', Duration::seconds(1), Duration::seconds(1)),
                mqttEnabled: $mqttEnabled,
            ),
            self::createInitialSnapshot(),
        );
    }

    private function getWorkerHarness(): WorkerHarness
    {
        $worker = $this->worker;
        self::assertNotNull($worker);

        return $worker;
    }

    private function send(object $message): void
    {
        $this->getWorkerHarness()->send($message);
    }

    private function sendToggle(): void
    {
        $this->sendChanges(self::createChange(self::WATCHED, 'off', 'on'));
    }

    private function sendChanges(StateChange ...$changes): void
    {
        $this->getWorkerHarness()->sendChanges(...$changes);
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @param (Closure(T): bool)|null $matches
     * @param (Closure(object): void)|null $collect
     * @return T
     */
    private function receiveUntil(string $class, ?Closure $matches = null, ?Closure $collect = null): object
    {
        return $this->getWorkerHarness()->receiveUntil($class, $matches, $collect);
    }

    private function shutDown(string $reason): string
    {
        $summary = $this->getWorkerHarness()->shutDown($reason);
        $this->worker = null;

        return $summary;
    }

    private static function createChange(string $entityId, string $from, string $to): StateChange
    {
        return new StateChange(new EntityId($entityId), new EntityState(new EntityId($entityId), $from), new EntityState(new EntityId($entityId), $to));
    }

    /** @param list<LogRecord> $logs */
    private static function findLog(array $logs, string $message): ?LogRecord
    {
        foreach ($logs as $log) {
            if ($log->message === $message) {
                return $log;
            }
        }

        return null;
    }

    private static function createResyncSnapshot(): EntityStatesFragment
    {
        return EntityStatesFragment::fromCollection(EntityStateCollection::keyedByEntityId([
            new EntityState(new EntityId(self::WATCHED), 'on'),
            new EntityState(new EntityId(self::LIGHT), 'on'),
        ]));
    }

    private static function createInitialSnapshot(): EntityStatesFragment
    {
        return EntityStatesFragment::fromCollection(EntityStateCollection::keyedByEntityId([
            new EntityState(new EntityId(self::WATCHED), 'off', ['friendly_name' => 'Protocol Test']),
            new EntityState(new EntityId('sensor.kitchen_temperature'), '21.5'),
            new EntityState(new EntityId(self::LIGHT), 'off'),
            new EntityState(new EntityId(SerialHandler::ENTITY), '1'),
        ]));
    }
}
