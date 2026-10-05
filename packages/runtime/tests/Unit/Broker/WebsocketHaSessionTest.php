<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Broker;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Stewart\Client\Connection\ConnectionConfig;
use Stewart\Client\Connection\HaConnection;
use Stewart\Client\Connection\HomeAssistantUrl;
use Stewart\Client\Event\EventDecoder;
use Stewart\Client\Exception\HaClientError;
use Stewart\Client\Exception\HaClientException;
use Stewart\Client\HaClient;
use Stewart\Client\State\EntityStateDecoder;
use Stewart\Contracts\State\StateChange;
use Stewart\Contracts\Time\Duration;
use Stewart\Contracts\Trigger\HaTrigger;
use Stewart\Contracts\Trigger\TriggerSpec;
use Stewart\Runtime\Broker\Reconnector;
use Stewart\Runtime\Broker\Trigger\HaTriggerLink;
use Stewart\Runtime\Broker\WebsocketHaSession;
use Stewart\Runtime\Config\BackoffPolicy;
use Stewart\Runtime\State\StateCache;
use Stewart\Runtime\Tests\Fixtures\Broker\RecordingSessionListener;
use Stewart\Testing\Exception\AssertsReason;
use Stewart\Testing\Logging\RecordingLogger;
use Stewart\Testing\Time\EventLoopTicks;
use Stewart\Testing\Time\ManualTimers;
use Stewart\Testing\Websocket\FakeWebsocketConnection;
use Stewart\Testing\Websocket\FakeWebsocketConnector;

use function Amp\async;

#[CoversClass(WebsocketHaSession::class)]
#[CoversClass(HaTriggerLink::class)]
final class WebsocketHaSessionTest extends TestCase
{
    use AssertsReason;

    private const int FIRST_SUBSCRIPTION = 1;

    private const string HALL = 'light.hall';

    private const string HA_USER_ID = 'stewart-user';

    private const string PORCH = 'light.porch';

    private ManualTimers $timers;

    private RecordingSessionListener $listener;

    private RecordingLogger $logger;

    private ?WebsocketHaSession $session = null;

    protected function setUp(): void
    {
        $this->timers = new ManualTimers();
        $this->listener = new RecordingSessionListener();
        $this->logger = new RecordingLogger();
    }

    protected function tearDown(): void
    {
        $this->session?->close();
        EventLoopTicks::settle();
    }

    public function testSubscribesBeforeSeeding(): void
    {
        $socket = self::createHaSocket(['hall' => 'off', 'porch' => 'on']);
        $session = $this->open($socket);

        self::assertSame(['auth', 'subscribe_events', 'get_config', 'auth/current_user', 'get_states'], array_column($socket->sent, 'type'));
        self::assertTrue($session->isConnected());
        self::assertSame(self::HA_USER_ID, $session->getHaUserId());
        self::assertSame('Europe/Budapest', $session->getTimeZone()->getName());
        self::assertSame([self::HALL, self::PORCH], $session->listEntityIds());
        self::assertSame(2, $session->countEntities());
        self::assertSame(1, $session->snapshotStateCache()->revision);
    }

    public function testChangesDuringSeedAreAppliedSilently(): void
    {
        $socket = self::createHaSocket(['hall' => 'off', 'porch' => 'off'], during: [
            self::createStateChangedFrame(self::HALL, 'on'),
            self::createStateChangedFrame(self::HALL, 'dimmed'),
            self::createEventFiredFrame('zha_event'),
        ]);
        $session = $this->open($socket);

        $states = [];

        foreach ($session->snapshotStateCache()->states->collection as $state) {
            $states[$state->entityId->value] = $state->state;
        }

        self::assertSame([self::HALL => 'dimmed', self::PORCH => 'off'], $states, 'The latest buffered change per entity wins.');
        self::assertSame(1, $session->snapshotStateCache()->revision);
        self::assertSame([], $this->listener->changes);
        self::assertSame([], $this->listener->events, 'An event during establish would reach a handler before the state cache is rebuilt.');
    }

    public function testChangeAfterSeedBumpsRevision(): void
    {
        $socket = self::createHaSocket(['hall' => 'off']);
        $session = $this->open($socket);

        $socket->queueFrame(self::createStateChangedFrame(self::HALL, 'on'));
        EventLoopTicks::settleUntil(fn(): bool => \count($this->listener->changes) === 1);

        self::assertSame([self::HALL], array_map(static fn(StateChange $change): string => $change->entityId->value, $this->listener->changes));
        self::assertSame(2, $session->snapshotStateCache()->revision);
    }

    public function testDropReseedsOnNewSocket(): void
    {
        $first = self::createHaSocket(['hall' => 'off']);
        $unreachable = new FakeWebsocketConnection();
        $unreachable->close();
        $second = self::createHaSocket(['hall' => 'on'], authenticated: false);
        $session = $this->open($first, $unreachable, $second);

        $first->close(1001, 'restarting');
        EventLoopTicks::settleUntil(fn(): bool => \count($this->listener->lost) === 1);

        self::assertCount(1, $this->listener->lost);
        self::assertStringContainsString('restarting', $this->listener->lost[0]);
        self::assertFalse($session->isConnected(), 'The second socket has not answered its handshake yet.');

        self::authenticate($second);
        EventLoopTicks::settleUntil(static fn(): bool => $session->isConnected());

        self::assertTrue($session->isConnected());
        self::assertSame(['auth', 'subscribe_events', 'get_states'], array_column($second->sent, 'type'), 'The time zone and user are asked for once.');
        self::assertEquals([Duration::seconds(1)], $this->listener->outages, 'One failed attempt, then one backoff.');
        self::assertSame(2, $session->snapshotStateCache()->revision);
    }

    public function testTokenRejectedOnReconnectEndsTheSession(): void
    {
        $first = self::createHaSocket(['hall' => 'off']);
        $rejecting = new FakeWebsocketConnection();
        $rejecting->queueFrame(['type' => 'auth_required', 'ha_version' => '2026.9.0']);
        $rejecting->replyWhenSent('auth', ['type' => 'auth_invalid', 'message' => 'Invalid access token']);
        $this->open($first, $rejecting);

        $first->close(1001, 'restarting');
        EventLoopTicks::settleUntil(fn(): bool => \count($this->listener->failures) === 1);

        self::assertCount(1, $this->listener->failures);
        self::assertInstanceOf(HaClientException::class, $this->listener->failures[0]);
        self::assertSame(HaClientError::TokenRejected, $this->listener->failures[0]->reason);
        self::assertSame([], $this->listener->outages);
    }

    public function testThrowingReconnectListenerIsLogged(): void
    {
        $first = self::createHaSocket(['hall' => 'off']);
        $second = self::createHaSocket(['hall' => 'on']);
        $this->listener->failOnReconnect = true;
        $session = $this->open($first, $second);

        $first->close(1001, 'restarting');
        EventLoopTicks::settleUntil(fn(): bool => $this->logger->listMessagesAt('error') !== []);

        self::assertSame(['Failed handling a Home Assistant reconnect'], $this->logger->listMessagesAt('error'));
        self::assertTrue($session->isConnected());
    }

    public function testClosingWhileReconnectingEndsQuietly(): void
    {
        $first = self::createHaSocket(['hall' => 'off']);
        $second = self::createHaSocket(['hall' => 'off'], authenticated: false);
        $session = $this->open($first, $second);

        $first->close(1001, 'restarting');
        EventLoopTicks::settle();

        $session->close();
        EventLoopTicks::settle();

        self::assertSame([], $this->listener->outages);
        self::assertSame([], $this->listener->failures);
        self::assertFalse($session->isConnected());
    }

    public function testNothingReachesTheListenerAfterClose(): void
    {
        $socket = self::createHaSocket(['hall' => 'off']);
        $session = $this->open($socket);

        $session->close();
        $socket->queueFrame(self::createStateChangedFrame(self::HALL, 'on'));
        EventLoopTicks::settle();

        self::assertSame([], $this->listener->changes);
        self::assertSame([], $this->listener->lost);
    }

    public function testTokenRejectedOnTheFirstOpenEscapes(): void
    {
        $rejecting = new FakeWebsocketConnection();
        $rejecting->queueFrame(['type' => 'auth_required', 'ha_version' => '2026.9.0']);
        $rejecting->replyWhenSent('auth', ['type' => 'auth_invalid', 'message' => 'Invalid access token']);

        $this->assertThrowsReason(HaClientError::TokenRejected, fn() => $this->open($rejecting));
    }

    public function testDropDuringFirstEstablishIsRetried(): void
    {
        $first = FakeWebsocketConnector::createAuthenticatedConnection();
        $first->replyWhenSent('subscribe_events', ['type' => 'result', 'success' => true, 'result' => null]);
        $first->replyWhenSent('get_config', ['type' => 'result', 'success' => true, 'result' => ['time_zone' => 'Europe/Budapest']]);
        $second = self::createHaSocket(['hall' => 'on']);
        $session = $this->createSession($first, $second);

        $opening = async(fn() => $session->open($this->listener));
        EventLoopTicks::settle();

        $first->close(1001, 'restarting');
        $opening->await();

        self::assertTrue($session->isConnected());
        self::assertSame([], $this->listener->lost);
        self::assertSame(['auth', 'subscribe_events', 'auth/current_user', 'get_states'], array_column($second->sent, 'type'), 'The user was never answered, so it is asked again.');
        self::assertSame(1, $session->snapshotStateCache()->revision);
    }

    public function testTriggerSubscribedWhileConnectedIsSentAndFires(): void
    {
        $socket = self::createHaSocket(['hall' => 'off']);
        $session = $this->open($socket);

        $session->subscribeTrigger(self::createSunsetSpec());
        EventLoopTicks::settleUntil(static fn(): bool => $socket->listSentOfType('subscribe_trigger') !== []);
        $socket->queueFrame(self::createTriggerFrame(self::findTriggerSubscriptionId($socket)));
        EventLoopTicks::settleUntil(fn(): bool => $this->listener->firedTriggers !== []);

        self::assertSame([['trigger' => 'sun', 'event' => 'sunset']], $socket->listSentOfType('subscribe_trigger')[0]['trigger'] ?? null);
        self::assertCount(1, $this->listener->firedTriggers);
        self::assertNotNull($this->listener->firedTriggers[0]->firedAt, 'Home Assistant sends no fire time, so the broker stamps one.');
    }

    public function testTriggerSubscribedBeforeOpenIsSentOnConnect(): void
    {
        $socket = self::createHaSocket(['hall' => 'off']);
        $session = $this->createSession($socket);

        $session->subscribeTrigger(self::createSunsetSpec());
        $session->open($this->listener);

        self::assertSame(['auth', 'subscribe_events', 'subscribe_trigger', 'get_config', 'auth/current_user', 'get_states'], array_column($socket->sent, 'type'));
    }

    public function testTriggersAreReissuedAfterReconnect(): void
    {
        $first = self::createHaSocket(['hall' => 'off']);
        $second = self::createHaSocket(['hall' => 'on']);
        $session = $this->createSession($first, $second);
        $session->subscribeTrigger(self::createSunsetSpec());
        $session->open($this->listener);

        $first->close(1001, 'restarting');
        EventLoopTicks::settleUntil(static fn(): bool => $session->isConnected() && $second->listSentOfType('get_states') !== []);
        $second->queueFrame(self::createTriggerFrame(self::findTriggerSubscriptionId($second)));
        EventLoopTicks::settleUntil(fn(): bool => $this->listener->firedTriggers !== []);

        self::assertSame(['auth', 'subscribe_events', 'subscribe_trigger', 'get_states'], array_column($second->sent, 'type'));
        self::assertCount(1, $this->listener->firedTriggers);
    }

    public function testRefusedTriggerDoesNotFailTheConnect(): void
    {
        $socket = self::createHaSocket(['hall' => 'off'], triggerReply: [
            'type' => 'result',
            'success' => false,
            'error' => ['code' => 'unauthorized', 'message' => 'Unauthorized'],
        ]);
        $session = $this->createSession($socket);
        $session->subscribeTrigger(self::createSunsetSpec());

        $session->open($this->listener);

        self::assertTrue($session->isConnected());
        self::assertCount(1, $this->listener->rejectedTriggerReasons);
        self::assertSame([], $this->listener->failures);
        self::assertSame(['Home Assistant rejected a trigger subscription'], $this->logger->listMessagesAt('error'));
    }

    public function testTriggerFiredDuringEstablishIsDropped(): void
    {
        $socket = self::createHaSocket(['hall' => 'off'], during: [self::createTriggerFrame(2)]);
        $session = $this->createSession($socket);
        $session->subscribeTrigger(self::createSunsetSpec());

        $session->open($this->listener);
        EventLoopTicks::settle();

        self::assertSame(2, self::findTriggerSubscriptionId($socket));
        self::assertSame([], $this->listener->firedTriggers);
    }

    public function testUnsubscribeBeforeAnswerReleasesLateId(): void
    {
        $socket = self::createHaSocket(['hall' => 'off']);
        $socket->replyWhenSent('unsubscribe_events', ['type' => 'result', 'success' => true, 'result' => null]);
        $session = $this->open($socket);

        $session->subscribeTrigger(self::createSunsetSpec());
        $session->unsubscribeTrigger(self::createSunsetSpec());
        EventLoopTicks::settleUntil(static fn(): bool => $socket->listSentOfType('unsubscribe_events') !== []);

        self::assertSame(self::findTriggerSubscriptionId($socket), $socket->listSentOfType('unsubscribe_events')[0]['subscription'] ?? null);
    }

    public function testUnsubscribeDuringOutageSendsNothingStale(): void
    {
        $first = self::createHaSocket(['hall' => 'off']);
        $second = self::createHaSocket(['hall' => 'on'], authenticated: false);
        $session = $this->createSession($first, $second);
        $session->subscribeTrigger(self::createSunsetSpec());
        $session->open($this->listener);

        $first->close(1001, 'restarting');
        EventLoopTicks::settleUntil(fn(): bool => \count($this->listener->lost) === 1);
        $session->unsubscribeTrigger(self::createSunsetSpec());
        self::authenticate($second);
        EventLoopTicks::settleUntil(static fn(): bool => $session->isConnected());

        self::assertSame(['auth', 'subscribe_events', 'get_states'], array_column($second->sent, 'type'));
        self::assertSame([], $first->listSentOfType('unsubscribe_events'));
    }

    private function open(FakeWebsocketConnection ...$sockets): WebsocketHaSession
    {
        $session = $this->createSession(...$sockets);
        $session->open($this->listener);

        return $session;
    }

    private function createSession(FakeWebsocketConnection ...$sockets): WebsocketHaSession
    {
        $connection = new HaConnection(
            new ConnectionConfig(
                url: HomeAssistantUrl::parse('http://home-assistant.invalid:8123'),
                token: 'test-token',
                connectTimeout: Duration::seconds(1),
                commandTimeout: Duration::seconds(1),
                heartbeatInterval: null,
            ),
            $this->timers,
            new NullLogger(),
            new FakeWebsocketConnector(...$sockets),
        );

        $client = new HaClient($connection, new EventDecoder(new EntityStateDecoder()), new EntityStateDecoder(), new NullLogger());

        return $this->session = new WebsocketHaSession(
            $client,
            new Reconnector(
                reconnectBackoff: new BackoffPolicy(Duration::seconds(1), Duration::seconds(4)),
                logger: new NullLogger(),
                deadlines: $this->timers,
            ),
            $this->logger,
            $this->timers->clock,
            new StateCache(),
            new HaTriggerLink($client, new NullLogger()),
        );
    }

    /**
     * @param array<string, string> $lights
     * @param list<array<string, mixed>> $during
     * @param array<string, mixed> $triggerReply
     */
    private static function createHaSocket(
        array $lights,
        array $during = [],
        bool $authenticated = true,
        array $triggerReply = ['type' => 'result', 'success' => true, 'result' => null],
    ): FakeWebsocketConnection {
        $socket = $authenticated ? FakeWebsocketConnector::createAuthenticatedConnection() : new FakeWebsocketConnection();
        $states = [];

        foreach ($lights as $name => $state) {
            $states[] = ['entity_id' => 'light.' . $name, 'state' => $state];
        }

        $socket->replyWhenSent('subscribe_events', ['type' => 'result', 'success' => true, 'result' => null]);
        $socket->replyWhenSent('subscribe_trigger', $triggerReply);
        $socket->replyWhenSent('get_config', ['type' => 'result', 'success' => true, 'result' => ['time_zone' => 'Europe/Budapest']]);
        $socket->replyWhenSent('auth/current_user', ['type' => 'result', 'success' => true, 'result' => ['id' => self::HA_USER_ID]]);

        foreach ($during as $frame) {
            $socket->replyWhenSent('get_states', $frame);
        }

        $socket->replyWhenSent('get_states', ['type' => 'result', 'success' => true, 'result' => $states]);

        return $socket;
    }

    private static function authenticate(FakeWebsocketConnection $socket): void
    {
        $socket->replyWhenSent('auth', ['type' => 'auth_ok', 'ha_version' => '2026.9.0']);
        $socket->queueFrame(['type' => 'auth_required', 'ha_version' => '2026.9.0']);
    }

    /** @return array<string, mixed> */
    private static function createStateChangedFrame(string $entityId, string $state): array
    {
        return ['id' => self::FIRST_SUBSCRIPTION, 'type' => 'event', 'event' => [
            'event_type' => 'state_changed',
            'data' => ['entity_id' => $entityId, 'new_state' => ['entity_id' => $entityId, 'state' => $state]],
        ]];
    }

    /** @return array<string, mixed> */
    private static function createEventFiredFrame(string $eventType): array
    {
        return ['id' => self::FIRST_SUBSCRIPTION, 'type' => 'event', 'event' => ['event_type' => $eventType, 'data' => []]];
    }

    private static function createSunsetSpec(): TriggerSpec
    {
        return TriggerSpec::fromSpec(HaTrigger::onSunset());
    }

    private static function findTriggerSubscriptionId(FakeWebsocketConnection $socket): int
    {
        $id = $socket->listSentOfType('subscribe_trigger')[0]['id'] ?? null;
        self::assertIsInt($id);

        return $id;
    }

    /** @return array<string, mixed> */
    private static function createTriggerFrame(int $subscriptionId): array
    {
        return ['id' => $subscriptionId, 'type' => 'event', 'event' => [
            'variables' => ['trigger' => ['platform' => 'sun', 'event' => 'sunset']],
        ]];
    }
}
