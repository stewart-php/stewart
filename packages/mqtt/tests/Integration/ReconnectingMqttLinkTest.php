<?php

declare(strict_types=1);

namespace Stewart\Mqtt\Tests\Integration;

use Amp\DeferredFuture;
use Amp\Future;
use Amp\TimeoutCancellation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use Stewart\Contracts\Mqtt\MqttMessage;
use Stewart\Contracts\Mqtt\MqttQos;
use Stewart\Contracts\Time\Duration;
use Stewart\Mqtt\Connection\MqttConnection;
use Stewart\Mqtt\Connection\MqttConnector;
use Stewart\Mqtt\Exception\MqttClientError;
use Stewart\Mqtt\Exception\MqttClientException;
use Stewart\Mqtt\OutboundMqttQueue;
use Stewart\Mqtt\Packet\ConnectReturnCode;
use Stewart\Mqtt\Packet\PacketDecoder;
use Stewart\Mqtt\Packet\PacketReader;
use Stewart\Mqtt\Packet\PubAckPacket;
use Stewart\Mqtt\ReconnectingMqttLink;
use Stewart\Mqtt\Tests\Fixtures\FakeMqttServer;
use Stewart\Mqtt\Tests\Fixtures\FakeMqttServerEvent;
use Stewart\Mqtt\Tests\Fixtures\RecordingMqttRouter;
use Stewart\Runtime\Config\BackoffPolicy;
use Stewart\Runtime\Config\MqttConfig;
use Stewart\Runtime\Config\MqttServerUrl;
use Stewart\Support\Time\RevoltTimers;
use Stewart\Testing\Logging\RecordedLog;
use Stewart\Testing\Logging\RecordingLogger;

#[CoversClass(ReconnectingMqttLink::class)]
#[CoversClass(MqttConnection::class)]
#[CoversClass(MqttConnector::class)]
#[CoversClass(OutboundMqttQueue::class)]
final class ReconnectingMqttLinkTest extends TestCase
{
    private const float WAIT_SECONDS = 5;

    private FakeMqttServer $server;

    private RecordingLogger $logger;

    private RecordingMqttRouter $router;

    private ?ReconnectingMqttLink $link = null;

    protected function setUp(): void
    {
        $this->server = FakeMqttServer::startListening();
        $this->logger = new RecordingLogger();
        $this->router = new RecordingMqttRouter();
    }

    protected function tearDown(): void
    {
        $this->link?->close();
        $this->server->close();
    }

    public function testServerMessageReachesTheRouter(): void
    {
        $link = $this->createLink();
        $link->subscribeFilter('home/#');
        $subscribed = $this->server->waitFor(FakeMqttServerEvent::Subscribed);
        $link->startInBackground($this->router);
        self::await($subscribed);

        $routed = $this->router->waitForNextMessage();
        $this->server->publishToClients(new MqttMessage('home/hall/temp', '21.5', MqttQos::AtLeastOnce, true), 7);
        $message = self::await($routed);

        self::assertSame(['home/#'], $this->server->subscribedFilters);
        self::assertSame('home/hall/temp', $message->topic);
        self::assertSame('21.5', $message->payload);
        self::assertTrue($message->retain);
    }

    public function testPublishReachesTheServer(): void
    {
        $link = $this->createLink();
        $connected = $this->logger->waitForMessage('Connected to the MQTT server');
        $link->startInBackground($this->router);
        self::await($connected);

        $published = $this->server->waitFor(FakeMqttServerEvent::Published);
        $link->publishMessage(new MqttMessage('out/light', 'on', MqttQos::AtLeastOnce));
        self::await($published);

        $received = $this->server->received->getFirst();
        self::assertSame('out/light', $received?->topic);
        self::assertSame(MqttQos::AtLeastOnce, $received->qos);
        self::assertSame(['stewart-test'], $this->server->connectedClientIds);
    }

    public function testReconnectResubscribesAndFlushesBuffer(): void
    {
        $link = $this->createLink(reconnectDelay: Duration::milliseconds(300));
        $link->subscribeFilter('home/#');
        $subscribed = $this->server->waitFor(FakeMqttServerEvent::Subscribed);
        $link->startInBackground($this->router);
        self::await($subscribed);

        $lost = $this->logger->waitForMessage('Lost the MQTT connection');
        $this->server->dropClients();
        self::await($lost);

        $resubscribed = $this->server->waitFor(FakeMqttServerEvent::Subscribed);
        $published = $this->server->waitFor(FakeMqttServerEvent::Published);
        $link->publishMessage(new MqttMessage('out/buffered', '1', MqttQos::AtLeastOnce));
        $link->publishMessage(new MqttMessage('out/dropped', '0'));
        self::await($resubscribed);
        self::await($published);

        self::assertSame(['home/#', 'home/#'], $this->server->subscribedFilters);
        self::assertSame(['out/buffered'], $this->server->received->mapToList(static fn(MqttMessage $message): string => $message->topic));
        self::assertContains('MQTT message dropped while the server is unreachable', $this->logger->listMessagesAt(LogLevel::WARNING));
    }

    public function testSilentServerFailsTheKeepalive(): void
    {
        $this->server->answersPings = false;
        $link = $this->createLink(keepalive: Duration::milliseconds(100));
        $lost = $this->logger->waitForMessage('Lost the MQTT connection');
        $link->startInBackground($this->router);
        self::await($lost);

        $reason = $this->findLoggedException('Lost the MQTT connection');
        self::assertInstanceOf(MqttClientException::class, $reason);
        self::assertSame(MqttClientError::KeepaliveTimedOut, $reason->reason);
    }

    public function testRefusedConnectionIsReportedAndRetried(): void
    {
        $this->server->connectAnswer = ConnectReturnCode::CredentialsInvalid;
        $link = $this->createLink();
        $refused = $this->logger->waitForMessage('Could not reach the MQTT server');
        $link->startInBackground($this->router);
        self::await($refused);

        $reason = $this->findLoggedException('Could not reach the MQTT server');
        self::assertInstanceOf(MqttClientException::class, $reason);
        self::assertSame(MqttClientError::ConnectionRefused, $reason->reason);
        self::assertContains('Could not reach the MQTT server', $this->logger->listMessagesAt(LogLevel::ERROR));
    }

    public function testCloseDisconnectsCleanly(): void
    {
        $link = $this->createLink(will: new MqttMessage('stewart/status', 'offline', MqttQos::AtLeastOnce, true));
        $connected = $this->logger->waitForMessage('Connected to the MQTT server');
        $link->startInBackground($this->router);
        self::await($connected);

        $disconnected = $this->server->waitFor(FakeMqttServerEvent::Disconnected);
        $link->close();
        self::await($disconnected);

        self::assertSame('stewart/status', $this->server->lastWill?->topic);
        self::assertSame('offline', $this->server->lastWill->payload);
    }

    public function testCloseDuringHandshakeStillDisconnects(): void
    {
        /** @var DeferredFuture<null> $connAck */
        $connAck = new DeferredFuture();
        $this->server->connAckGate = $connAck->getFuture();
        $link = $this->createLink(will: new MqttMessage('stewart/status', 'offline'));
        $connectReceived = $this->server->waitFor(FakeMqttServerEvent::ConnectReceived);
        $link->startInBackground($this->router);
        self::await($connectReceived);

        $disconnected = $this->server->waitFor(FakeMqttServerEvent::Disconnected);
        $link->close();
        $connAck->complete();
        self::await($disconnected);

        self::assertNotContains('Connected to the MQTT server', $this->logger->listMessagesAt(LogLevel::INFO));
    }

    public function testStrayAcknowledgementDropsTheConnection(): void
    {
        $link = $this->createLink();
        $connected = $this->logger->waitForMessage('Connected to the MQTT server');
        $link->startInBackground($this->router);
        self::await($connected);

        $lost = $this->logger->waitForMessage('Lost the MQTT connection');
        $this->server->sendToClients(new PubAckPacket(4242)->encodePacket());
        self::await($lost);

        $reason = $this->findLoggedException('Lost the MQTT connection');
        self::assertInstanceOf(MqttClientException::class, $reason);
        self::assertSame(MqttClientError::PacketUnexpected, $reason->reason);
    }

    private function createLink(?Duration $keepalive = null, ?Duration $reconnectDelay = null, ?MqttMessage $will = null): ReconnectingMqttLink
    {
        $timers = new RevoltTimers();
        $reconnectDelay ??= Duration::milliseconds(20);
        $config = new MqttConfig(
            serverUrl: MqttServerUrl::parse($this->server->buildUrl()),
            clientId: 'stewart-test',
            keepalive: $keepalive ?? Duration::seconds(30),
            connectTimeout: Duration::seconds(2),
            reconnectBackoff: new BackoffPolicy($reconnectDelay, $reconnectDelay),
            outboundBuffer: 10,
            will: $will,
        );

        return $this->link = new ReconnectingMqttLink(
            $config,
            new MqttConnector(new PacketReader(new PacketDecoder()), $timers, $timers),
            new OutboundMqttQueue($config->outboundBuffer),
            $timers,
            $this->logger,
        );
    }

    private function findLoggedException(string $message): mixed
    {
        return $this->logger->records
            ->filter(static fn(RecordedLog $record): bool => $record->message === $message)
            ->getFirst()?->context['exception'] ?? null;
    }

    /**
     * @template T
     * @param Future<T> $future
     * @return T
     */
    private static function await(Future $future): mixed
    {
        return $future->await(new TimeoutCancellation(self::WAIT_SECONDS));
    }
}
