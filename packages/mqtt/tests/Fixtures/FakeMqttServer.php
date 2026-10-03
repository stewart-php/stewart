<?php

declare(strict_types=1);

namespace Stewart\Mqtt\Tests\Fixtures;

use Amp\ByteStream\BufferedReader;
use Amp\DeferredFuture;
use Amp\Future;
use Amp\Socket\InternetAddress;
use Amp\Socket\ServerSocket;
use Amp\Socket\Socket;
use Stewart\Contracts\Mqtt\Collection\MqttMessageCollection;
use Stewart\Contracts\Mqtt\MqttMessage;
use Stewart\Contracts\Mqtt\MqttQos;
use Stewart\Mqtt\Packet\ConnectReturnCode;
use Stewart\Mqtt\Packet\PacketBytes;
use Stewart\Mqtt\Packet\PacketType;
use Stewart\Mqtt\Packet\PubAckPacket;
use Stewart\Mqtt\Packet\PublishPacket;
use Throwable;

use function Amp\async;
use function Amp\Socket\listen;

// Speaks just enough of the server side of MQTT 3.1.1 to drive the client over a real socket.
final class FakeMqttServer
{
    private const int TYPE_SHIFT = 4;

    private const int FLAGS_MASK = 0x0F;

    private const int WILL_FLAG = 0x04;

    private const int PUBLISH_QOS_SHIFT = 1;

    private const int PUBLISH_QOS_MASK = 0x03;

    private const int PUBLISH_RETAIN_FLAG = 0x01;

    private const int LENGTH_DIGIT_BASE = 128;

    private const string GRANTED_AT_LEAST_ONCE = "\x01";

    public ConnectReturnCode $connectAnswer = ConnectReturnCode::Accepted;

    public bool $answersPings = true;

    /** @var Future<null>|null */
    public ?Future $connAckGate = null;

    /** @var list<string> */
    public array $connectedClientIds = [];

    public ?MqttMessage $lastWill = null;

    /** @var list<string> */
    public array $subscribedFilters = [];

    /** @var list<string> */
    public array $unsubscribedFilters = [];

    public private(set) MqttMessageCollection $received;

    /** @var list<Socket> */
    private array $clients = [];

    /** @var array<string, DeferredFuture<null>> */
    private array $waiters = [];

    private function __construct(private readonly ServerSocket $server)
    {
        $this->received = MqttMessageCollection::empty();
    }

    public static function startListening(): self
    {
        $fake = new self(listen('127.0.0.1:0'));
        async($fake->acceptClients(...));

        return $fake;
    }

    public function buildUrl(): string
    {
        $address = $this->server->getAddress();
        \assert($address instanceof InternetAddress);

        return 'mqtt://127.0.0.1:' . $address->getPort();
    }

    /** @return Future<null> */
    public function waitFor(FakeMqttServerEvent $event): Future
    {
        return ($this->waiters[$event->name] ??= new DeferredFuture())->getFuture();
    }

    public function publishToClients(MqttMessage $message, ?int $packetId = null): void
    {
        $this->sendToClients(new PublishPacket($message, $packetId)->encodePacket());
    }

    public function sendToClients(string $bytes): void
    {
        foreach ($this->clients as $client) {
            $client->write($bytes);
        }
    }

    public function dropClients(): void
    {
        foreach ($this->clients as $client) {
            $client->close();
        }

        $this->clients = [];
    }

    public function close(): void
    {
        $this->dropClients();
        $this->server->close();
    }

    private function acceptClients(): void
    {
        while (($client = $this->server->accept()) !== null) {
            $this->clients[] = $client;
            async(fn() => $this->serveClient($client));
        }
    }

    private function serveClient(Socket $client): void
    {
        $reader = new BufferedReader($client);

        try {
            while ($client->isReadable()) {
                $header = \ord($reader->readLength(1));
                $length = $this->readRemainingLength($reader);
                $body = $length > 0 ? $reader->readLength($length) : '';
                $this->answerPacket($client, PacketType::from($header >> self::TYPE_SHIFT), $header & self::FLAGS_MASK, $body);
            }
        } catch (Throwable) {
            $client->close();
        }
    }

    private function answerPacket(Socket $client, PacketType $type, int $flags, string $body): void
    {
        match ($type) {
            PacketType::Connect => $this->answerConnect($client, $body),
            PacketType::Publish => $this->answerPublish($client, $flags, $body),
            PacketType::Subscribe => $this->answerSubscribe($client, $body),
            PacketType::Unsubscribe => $this->answerUnsubscribe($client, $body),
            PacketType::PingReq => $this->answerPingRequest($client),
            PacketType::Disconnect => $this->recordDisconnect($client),
            default => null,
        };
    }

    private function answerConnect(Socket $client, string $body): void
    {
        $offset = 0;
        self::readString($body, $offset);
        ++$offset;
        $flags = \ord($body[$offset++]);
        $offset += 2;
        $this->connectedClientIds[] = self::readString($body, $offset);

        if (($flags & self::WILL_FLAG) !== 0) {
            $this->lastWill = new MqttMessage(self::readString($body, $offset), self::readString($body, $offset));
        }

        $this->signal(FakeMqttServerEvent::ConnectReceived);
        $this->connAckGate?->await();
        $client->write(PacketBytes::assemblePacket(PacketType::ConnAck, 0, "\x00" . \chr($this->connectAnswer->value)));

        if ($this->connectAnswer !== ConnectReturnCode::Accepted) {
            $client->close();
        }
    }

    private function answerPublish(Socket $client, int $flags, string $body): void
    {
        $offset = 0;
        $topic = self::readString($body, $offset);
        $qos = MqttQos::from(($flags >> self::PUBLISH_QOS_SHIFT) & self::PUBLISH_QOS_MASK);
        $packetId = $qos === MqttQos::AtLeastOnce ? self::readUint16($body, $offset) : null;

        $this->received = $this->received->withMqttMessage(
            new MqttMessage($topic, substr($body, $offset), $qos, ($flags & self::PUBLISH_RETAIN_FLAG) !== 0),
        );

        if ($packetId !== null) {
            $client->write(new PubAckPacket($packetId)->encodePacket());
        }

        $this->signal(FakeMqttServerEvent::Published);
    }

    private function answerSubscribe(Socket $client, string $body): void
    {
        $offset = 0;
        $packetId = self::readUint16($body, $offset);
        $this->subscribedFilters[] = self::readString($body, $offset);
        $client->write(PacketBytes::assemblePacket(PacketType::SubAck, 0, PacketBytes::encodeUint16($packetId) . self::GRANTED_AT_LEAST_ONCE));
        $this->signal(FakeMqttServerEvent::Subscribed);
    }

    private function answerUnsubscribe(Socket $client, string $body): void
    {
        $offset = 0;
        $packetId = self::readUint16($body, $offset);
        $this->unsubscribedFilters[] = self::readString($body, $offset);
        $client->write(PacketBytes::assemblePacket(PacketType::UnsubAck, 0, PacketBytes::encodeUint16($packetId)));
    }

    private function answerPingRequest(Socket $client): void
    {
        if ($this->answersPings) {
            $client->write(PacketBytes::assemblePacket(PacketType::PingResp, 0, ''));
        }
    }

    private function recordDisconnect(Socket $client): void
    {
        $client->close();
        $this->signal(FakeMqttServerEvent::Disconnected);
    }

    private function signal(FakeMqttServerEvent $event): void
    {
        $waiter = $this->waiters[$event->name] ?? null;
        unset($this->waiters[$event->name]);
        $waiter?->complete();
    }

    private function readRemainingLength(BufferedReader $reader): int
    {
        $length = 0;
        $multiplier = 1;

        do {
            $digit = \ord($reader->readLength(1));
            $length += ($digit % self::LENGTH_DIGIT_BASE) * $multiplier;
            $multiplier *= self::LENGTH_DIGIT_BASE;
        } while ($digit >= self::LENGTH_DIGIT_BASE);

        return $length;
    }

    private static function readUint16(string $body, int &$offset): int
    {
        $value = (\ord($body[$offset]) << 8) | \ord($body[$offset + 1]);
        $offset += 2;

        return $value;
    }

    private static function readString(string $body, int &$offset): string
    {
        $length = self::readUint16($body, $offset);
        $value = substr($body, $offset, $length);
        $offset += $length;

        return $value;
    }
}
