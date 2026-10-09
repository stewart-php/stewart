<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Broker\Component;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Stewart\Client\Component\ComponentEventDecoder;
use Stewart\Client\Component\ComponentInstance;
use Stewart\Client\Connection\ConnectionConfig;
use Stewart\Client\Connection\HaConnection;
use Stewart\Client\Connection\HomeAssistantUrl;
use Stewart\Client\Event\EventDecoder;
use Stewart\Client\Exception\HaClientError;
use Stewart\Client\HaClient;
use Stewart\Client\Registry\RegistryDecoder;
use Stewart\Client\State\EntityStateDecoder;
use Stewart\Contracts\Time\Duration;
use Stewart\Runtime\Broker\Component\ComponentLink;
use Stewart\Runtime\Broker\Component\ComponentTracker;
use Stewart\Runtime\Config\ExposeConfig;
use Stewart\Runtime\Lifecycle\ComponentState;
use Stewart\Testing\Exception\AssertsReason;
use Stewart\Testing\Time\EventLoopTicks;
use Stewart\Testing\Time\ManualTimers;
use Stewart\Testing\Websocket\FakeWebsocketConnection;
use Stewart\Testing\Websocket\FakeWebsocketConnector;

#[CoversClass(ComponentLink::class)]
#[CoversClass(ComponentTracker::class)]
final class ComponentLinkTest extends TestCase
{
    use AssertsReason;

    private const string STEWART_VERSION = '0.9.0';

    private ManualTimers $timers;

    private FakeWebsocketConnection $socket;

    private HaClient $client;

    private ComponentLink $link;

    private ComponentTracker $tracker;

    protected function setUp(): void
    {
        $this->timers = new ManualTimers();
        $this->socket = FakeWebsocketConnector::createAuthenticatedConnection();
        $this->client = $this->createClient();
        $this->tracker = new ComponentTracker($this->timers->clock);
        $this->link = new ComponentLink($this->client, new NullLogger(), $this->tracker, new ExposeConfig(ComponentInstance::parse('upstairs')), self::STEWART_VERSION);
    }

    public function testLinkIsUncheckedBeforeFirstConnect(): void
    {
        self::assertSame(ComponentState::Unchecked, $this->tracker->detection->state);
    }

    public function testMissingComponentOpensNoSession(): void
    {
        $this->socket->replyWhenSent('stewart/version', self::createRejection('unknown_command'));
        $this->connectAndEstablish();

        self::assertSame(ComponentState::Missing, $this->tracker->detection->state);
        self::assertSame([], $this->socket->listSentOfType('stewart/session/subscribe'));
    }

    public function testCompatibleComponentOpensSession(): void
    {
        $this->replyWithVersion(1);
        $this->replyToSessionSubscribe();
        $this->connectAndEstablish();

        $detection = $this->tracker->detection;
        $subscribe = $this->socket->listSentOfType('stewart/session/subscribe')[0] ?? [];

        self::assertSame(ComponentState::Active, $detection->state);
        self::assertSame('0.9.1', $detection->version?->componentVersion);
        self::assertSame('upstairs', $subscribe['instance'] ?? null);
        self::assertSame(self::STEWART_VERSION, $subscribe['stewart_version'] ?? null);
    }

    public function testOtherProtocolOpensNoSession(): void
    {
        $this->replyWithVersion(2);
        $this->connectAndEstablish();

        self::assertSame(ComponentState::ProtocolMismatch, $this->tracker->detection->state);
        self::assertSame([], $this->socket->listSentOfType('stewart/session/subscribe'));
    }

    public function testRefusedSessionKeepsVersion(): void
    {
        $this->replyWithVersion(1);
        $this->socket->replyWhenSent('stewart/session/subscribe', self::createRejection('protocol_mismatch'));
        $this->connectAndEstablish();

        $detection = $this->tracker->detection;

        self::assertSame(ComponentState::Refused, $detection->state);
        self::assertSame('0.9.1', $detection->version?->componentVersion);
    }

    public function testTransientFailurePropagates(): void
    {
        $this->assertThrowsReason(HaClientError::NotConnected, fn() => $this->link->establishLink());
    }

    public function testReplacedSessionIsRecorded(): void
    {
        $this->replyWithVersion(1);
        $this->replyToSessionSubscribe();
        $this->connectAndEstablish();

        $this->pushSessionReplaced();

        self::assertSame(ComponentState::Replaced, $this->tracker->detection->state);
    }

    public function testEventAfterLostLinkIsIgnored(): void
    {
        $this->replyWithVersion(1);
        $this->replyToSessionSubscribe();
        $this->connectAndEstablish();

        $this->link->markLinkLost();
        $this->pushSessionReplaced();

        $detection = $this->tracker->detection;

        self::assertSame(ComponentState::Unchecked, $detection->state);
        self::assertSame('0.9.1', $detection->version?->componentVersion);
    }

    private function connectAndEstablish(): void
    {
        $this->client->connect();
        $this->link->establishLink();
    }

    private function replyWithVersion(int $protocol): void
    {
        $this->socket->replyWhenSent('stewart/version', ['type' => 'result', 'success' => true, 'result' => ['component_version' => '0.9.1', 'protocol' => $protocol]]);
    }

    private function replyToSessionSubscribe(): void
    {
        $this->socket->replyWhenSent('stewart/session/subscribe', ['type' => 'result', 'success' => true, 'result' => null]);
    }

    private function pushSessionReplaced(): void
    {
        $subscriptionId = $this->socket->listSentOfType('stewart/session/subscribe')[0]['id'] ?? null;
        self::assertIsInt($subscriptionId);

        $this->socket->queueFrame(['id' => $subscriptionId, 'type' => 'event', 'event' => ['type' => 'session_replaced']]);
        EventLoopTicks::settle();
        $this->client->flushEvents();
    }

    /** @return array<string, mixed> */
    private static function createRejection(string $code): array
    {
        return ['type' => 'result', 'success' => false, 'error' => ['code' => $code, 'message' => 'Refused.']];
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
