<?php

declare(strict_types=1);

namespace Stewart\Mqtt\Tests\Persistence;

use Amp\Future;
use Amp\TimeoutCancellation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Mqtt\MqttMessage;
use Stewart\Contracts\Mqtt\MqttQos;
use Stewart\Contracts\Time\Duration;
use Stewart\Mqtt\Connection\MqttConnector;
use Stewart\Mqtt\OutboundMqttQueue;
use Stewart\Mqtt\Packet\PacketDecoder;
use Stewart\Mqtt\Packet\PacketReader;
use Stewart\Mqtt\ReconnectingMqttLink;
use Stewart\Mqtt\Tests\Fixtures\RecordingMqttRouter;
use Stewart\Runtime\Config\BackoffPolicy;
use Stewart\Runtime\Config\MqttConfig;
use Stewart\Runtime\Config\MqttServerUrl;
use Stewart\Support\Time\RevoltTimers;
use Stewart\Testing\Logging\RecordingLogger;

#[CoversClass(ReconnectingMqttLink::class)]
final class MosquittoRoundTripTest extends TestCase
{
    private const float WAIT_SECONDS = 5;

    private ReconnectingMqttLink $link;

    private RecordingLogger $logger;

    private string $topicRoot;

    protected function setUp(): void
    {
        $this->logger = new RecordingLogger();
        $this->topicRoot = 'stewart-test/' . bin2hex(random_bytes(4));
        $this->link = $this->createLink();
    }

    protected function tearDown(): void
    {
        $this->link->publishMessage(new MqttMessage($this->topicRoot . '/retained', '', MqttQos::AtLeastOnce, true));
        $this->link->close();
    }

    public function testPublishedMessageComesBackThroughFilter(): void
    {
        $router = new RecordingMqttRouter();
        $connected = $this->logger->waitForMessage('Connected to the MQTT server');
        $this->link->subscribeFilter($this->topicRoot . '/+/temp');
        $this->link->startInBackground($router);
        self::await($connected);

        $routed = $router->waitForNextMessage();
        $this->link->publishMessage(new MqttMessage($this->topicRoot . '/hall/temp', "21.5\xff", MqttQos::AtLeastOnce));
        $message = self::await($routed);

        self::assertSame($this->topicRoot . '/hall/temp', $message->topic);
        self::assertSame("21.5\xff", $message->payload);
    }

    public function testRetainedMessageArrivesOnSubscribe(): void
    {
        $router = new RecordingMqttRouter();
        $connected = $this->logger->waitForMessage('Connected to the MQTT server');
        $this->link->startInBackground($router);
        self::await($connected);

        $this->link->publishMessage(new MqttMessage($this->topicRoot . '/retained', 'kept', MqttQos::AtLeastOnce, true));
        $routed = $router->waitForNextMessage();
        $this->link->subscribeFilter($this->topicRoot . '/#');
        $message = self::await($routed);

        self::assertSame('kept', $message->payload);
        self::assertTrue($message->retain);
    }

    private function createLink(): ReconnectingMqttLink
    {
        $timers = new RevoltTimers();
        $url = getenv('TEST_MQTT_URL');
        $delay = Duration::milliseconds(100);
        $config = new MqttConfig(
            serverUrl: MqttServerUrl::parse($url === false || $url === '' ? 'mqtt://mosquitto:1883' : $url),
            clientId: 'stewart-' . bin2hex(random_bytes(4)),
            keepalive: Duration::seconds(30),
            connectTimeout: Duration::seconds(2),
            reconnectBackoff: new BackoffPolicy($delay, $delay),
            outboundBuffer: 10,
            will: null,
        );

        return new ReconnectingMqttLink(
            $config,
            new MqttConnector(new PacketReader(new PacketDecoder()), $timers, $timers),
            new OutboundMqttQueue($config->outboundBuffer),
            $timers,
            $this->logger,
        );
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
