<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Broker\Component;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;
use Stewart\Client\Component\ComponentEventDecoder;
use Stewart\Client\Component\ComponentInstance;
use Stewart\Client\Component\ComponentProtocol;
use Stewart\Client\Component\ExposedEntityDefinition;
use Stewart\Client\Connection\ConnectionConfig;
use Stewart\Client\Connection\HaConnection;
use Stewart\Client\Connection\HomeAssistantUrl;
use Stewart\Client\Event\EventDecoder;
use Stewart\Client\HaClient;
use Stewart\Client\Registry\RegistryDecoder;
use Stewart\Client\State\EntityStateDecoder;
use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Exposure\ExposedEntityKey;
use Stewart\Contracts\Exposure\ExposedState;
use Stewart\Contracts\Exposure\ExposedStateChange;
use Stewart\Contracts\Exposure\SensorConfig;
use Stewart\Contracts\Time\Duration;
use Stewart\Runtime\Broker\Component\ComponentLink;
use Stewart\Runtime\Broker\Component\ComponentSession;
use Stewart\Runtime\Broker\Component\ComponentTracker;
use Stewart\Runtime\Broker\Exposure\ExposureLink;
use Stewart\Runtime\Broker\Exposure\ExposureReconciler;
use Stewart\Runtime\Config\ExposeConfig;
use Stewart\Runtime\Lifecycle\ComponentState;
use Stewart\Runtime\Model\StewartVersion;
use Stewart\Runtime\Model\WorkerId;
use Stewart\Runtime\Tests\Fixtures\Broker\AppTopologyFixture;
use Stewart\Runtime\Tests\Fixtures\Config\ConfigFixture;
use Stewart\Testing\Logging\RecordingLogger;
use Stewart\Testing\Time\EventLoopTicks;
use Stewart\Testing\Time\ManualTimers;
use Stewart\Testing\Websocket\FakeWebsocketConnection;
use Stewart\Testing\Websocket\FakeWebsocketConnector;

#[CoversClass(ComponentSession::class)]
final class ComponentSessionTest extends TestCase
{
    private const string APP = 'climate';

    private const string KEY = 'average_temperature';

    private ManualTimers $timers;

    private FakeWebsocketConnection $socket;

    private ComponentTracker $tracker;

    private ExposureLink $exposures;

    private ComponentSession $session;

    private RecordingLogger $logger;

    private HaClient $client;

    protected function setUp(): void
    {
        $this->timers = new ManualTimers();
        $this->socket = FakeWebsocketConnector::createAuthenticatedConnection();
        $this->tracker = new ComponentTracker($this->timers->clock);
        $this->logger = new RecordingLogger();
        $this->client = $this->createClient();
        $this->session = $this->createSession(new ExposeConfig(ComponentInstance::parse('default'), Duration::seconds(10)));

        $this->client->connect();
    }

    public function testOpenSessionUpsertsKeptExposures(): void
    {
        $this->exposeSensorBeforeSession();
        $this->replyToNextOpen();

        $this->session->openSession();

        self::assertSame(ComponentState::Active, $this->tracker->detection->state);
        self::assertCount(1, $this->socket->listSentOfType('stewart/entity/upsert'));
    }

    public function testNoSessionReplyOpensNewSessionAndReplays(): void
    {
        $this->openSessionWithExposedSensor();
        $this->socket->replyWhenSent('stewart/entity/state', self::createRejection('no_session'));
        $this->replyToNextOpen();

        $this->exposures->updateEntity(new AppId(self::APP), new ExposedEntityKey(self::KEY), new ExposedStateChange(new ExposedState(21.5)));
        EventLoopTicks::settleUntil(fn(): bool => \count($this->socket->listSentOfType('stewart/entity/upsert')) === 2);

        self::assertCount(2, $this->socket->listSentOfType('stewart/session/subscribe'));
        self::assertSame(21.5, $this->socket->listSentOfType('stewart/entity/upsert')[1]['state'] ?? null);
        self::assertSame(ComponentState::Active, $this->tracker->detection->state);
    }

    public function testMissingComponentIsRetriedAfterBackoff(): void
    {
        $this->openSessionWithExposedSensor();
        $this->socket->replyWhenSent('stewart/version', self::createRejection('unknown_command'));
        $startedAt = $this->timers->clock->getMonotonicTime();

        $this->session->relinkAfterSessionEnded();
        $this->answerVersionRequest(3);

        self::assertSame(ComponentState::Active, $this->tracker->detection->state);
        self::assertSame('1s', (string) $this->timers->clock->getMonotonicTime()->elapsedSince($startedAt));
        self::assertCount(2, $this->socket->listSentOfType('stewart/entity/upsert'));
    }

    public function testComponentMissingOnConnectIsRetriedAfterBackoff(): void
    {
        $this->openSessionWithExposedSensor();
        $this->session->markLinkLost();
        $this->socket->replyWhenSent('stewart/version', self::createRejection('unknown_command'));
        $this->session->openSession();
        $startedAt = $this->timers->clock->getMonotonicTime();

        $this->session->startRelinkingUnlessSettled();
        $this->answerVersionRequest(3);

        self::assertSame(ComponentState::Active, $this->tracker->detection->state);
        self::assertSame('1s', (string) $this->timers->clock->getMonotonicTime()->elapsedSince($startedAt));
    }

    public function testActiveComponentIsNotRetried(): void
    {
        $this->openSessionWithExposedSensor();

        $this->session->startRelinkingUnlessSettled();
        EventLoopTicks::settle();

        self::assertCount(1, $this->socket->listSentOfType('stewart/version'));
    }

    public function testTimedOutRelinkIsRetried(): void
    {
        $this->openSessionWithExposedSensor();

        $this->session->relinkAfterSessionEnded();
        EventLoopTicks::settleUntil(fn(): bool => \count($this->socket->listSentOfType('stewart/version')) === 2);
        $this->timers->delay(Duration::seconds(1));
        $this->answerVersionRequest(3);

        self::assertSame(ComponentState::Active, $this->tracker->detection->state);
    }

    public function testLostConnectionEndsRelinking(): void
    {
        $this->openSessionWithExposedSensor();

        $this->session->relinkAfterSessionEnded();
        EventLoopTicks::settleUntil(fn(): bool => \count($this->socket->listSentOfType('stewart/version')) === 2);
        $this->socket->close();
        EventLoopTicks::settle();

        self::assertCount(2, $this->socket->listSentOfType('stewart/version'));
    }

    public function testDisabledLinkIsNotRetried(): void
    {
        $session = $this->createSession(new ExposeConfig(ComponentInstance::parse('default'), Duration::seconds(10), enabled: false));

        $session->openSession();
        $session->startRelinkingUnlessSettled();
        EventLoopTicks::settle();

        self::assertSame(ComponentState::Disabled, $this->tracker->detection->state);
        self::assertSame([], $this->socket->listSentOfType('stewart/version'));
    }

    public function testRepeatedMissingComponentIsLoggedOnce(): void
    {
        $this->openSessionWithExposedSensor();
        $this->socket->replyWhenSent('stewart/version', self::createRejection('unknown_command'));

        $this->session->relinkAfterSessionEnded();
        $this->rejectVersionRequest(3);
        $this->answerVersionRequest(4);

        $missing = array_filter($this->logger->listMessagesAt(LogLevel::INFO), static fn(string $message): bool => str_contains($message, 'not loaded yet'));

        self::assertCount(1, $missing);
    }

    public function testLostLinkStopsRelinking(): void
    {
        $this->openSessionWithExposedSensor();

        $this->session->relinkAfterSessionEnded();
        EventLoopTicks::settleUntil(fn(): bool => \count($this->socket->listSentOfType('stewart/version')) === 2);
        $this->session->markLinkLost();
        $this->rejectVersionRequest(2);
        EventLoopTicks::settle();

        self::assertCount(2, $this->socket->listSentOfType('stewart/version'));
    }

    public function testSecondSessionLossJoinsRunningRelink(): void
    {
        $this->openSessionWithExposedSensor();

        $this->session->relinkAfterSessionEnded();
        $this->session->relinkAfterSessionEnded();
        $this->answerVersionRequest(2);

        self::assertCount(2, $this->socket->listSentOfType('stewart/version'));
        self::assertSame(ComponentState::Active, $this->tracker->detection->state);
    }

    private function openSessionWithExposedSensor(): void
    {
        $this->replyToNextOpen();
        $this->session->openSession();
        $this->exposures->exposeEntity(new WorkerId(0), new AppId(self::APP), new ExposedEntityKey(self::KEY), ExposedEntityDefinition::fromConfig(new SensorConfig(), null), new ExposedStateChange(new ExposedState(21.0)));
    }

    private function exposeSensorBeforeSession(): void
    {
        $this->exposures->exposeEntity(new WorkerId(0), new AppId(self::APP), new ExposedEntityKey(self::KEY), ExposedEntityDefinition::fromConfig(new SensorConfig(), null), new ExposedStateChange(new ExposedState(21.0)));
    }

    private function replyToNextOpen(): void
    {
        $this->socket->replyWhenSent('stewart/version', ['type' => 'result', 'success' => true, 'result' => ['component_version' => '0.11.0', 'protocol' => ComponentProtocol::VERSION]]);
        $this->socket->replyWhenSent('stewart/session/subscribe', ['type' => 'result', 'success' => true, 'result' => null]);
        $this->socket->replyWhenSent('stewart/entity/upsert', ['type' => 'result', 'success' => true, 'result' => [
            'entity_id' => 'sensor.stewart_climate_average_temperature',
            'state' => 21.0,
            'attributes' => [],
            'available' => true,
        ]]);
    }

    private function answerVersionRequest(int $sentCount): void
    {
        $this->socket->replyWhenSent('stewart/session/subscribe', ['type' => 'result', 'success' => true, 'result' => null]);
        $this->socket->replyWhenSent('stewart/entity/upsert', ['type' => 'result', 'success' => true, 'result' => [
            'entity_id' => 'sensor.stewart_climate_average_temperature',
            'state' => 21.0,
            'attributes' => [],
            'available' => true,
        ]]);
        $this->queueReplyTo($sentCount, ['type' => 'result', 'success' => true, 'result' => ['component_version' => '0.11.0', 'protocol' => ComponentProtocol::VERSION]]);
        EventLoopTicks::settleUntil(fn(): bool => $this->tracker->isActive() && \count($this->socket->listSentOfType('stewart/entity/upsert')) === 2);
    }

    private function rejectVersionRequest(int $sentCount): void
    {
        $this->queueReplyTo($sentCount, self::createRejection('unknown_command'));
        EventLoopTicks::settle();
    }

    /** @param array<string, mixed> $reply */
    private function queueReplyTo(int $sentCount, array $reply): void
    {
        EventLoopTicks::settleUntil(fn(): bool => \count($this->socket->listSentOfType('stewart/version')) === $sentCount);
        $id = $this->socket->listSentOfType('stewart/version')[$sentCount - 1]['id'] ?? null;
        self::assertIsInt($id);

        $this->socket->queueFrame(['id' => $id, ...$reply]);
    }

    /** @return array<string, mixed> */
    private static function createRejection(string $code): array
    {
        return ['type' => 'result', 'success' => false, 'error' => ['code' => $code, 'message' => 'Refused.']];
    }

    private function createSession(ExposeConfig $expose): ComponentSession
    {
        $this->exposures = new ExposureLink($this->client, new NullLogger(), $this->tracker, $expose);

        return new ComponentSession(
            new ComponentLink($this->client, $this->logger, $this->tracker, $expose, new StewartVersion('0.11.0')),
            $this->exposures,
            new ExposureReconciler(AppTopologyFixture::createTopology(), $this->exposures, $this->client, $this->tracker, $expose, $this->timers, new NullLogger()),
            $this->tracker,
            ConfigFixture::createStewartConfig(['reconnect' => ['initial_delay' => '1s', 'max_delay' => '4s']]),
            $this->timers,
            $this->logger,
        );
    }

    private function createClient(): HaClient
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
            new FakeWebsocketConnector($this->socket),
        );

        return new HaClient($connection, new EventDecoder(new EntityStateDecoder()), new EntityStateDecoder(), new RegistryDecoder(), new ComponentEventDecoder(), new NullLogger());
    }
}
