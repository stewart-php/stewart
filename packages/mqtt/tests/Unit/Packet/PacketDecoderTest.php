<?php

declare(strict_types=1);

namespace Stewart\Mqtt\Tests\Unit\Packet;

use Amp\ByteStream\BufferedReader;
use Amp\ByteStream\ReadableBuffer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Mqtt\MqttQos;
use Stewart\Mqtt\Exception\MqttClientError;
use Stewart\Mqtt\Exception\MqttClientException;
use Stewart\Mqtt\Packet\ConnAckPacket;
use Stewart\Mqtt\Packet\ConnectReturnCode;
use Stewart\Mqtt\Packet\PacketBodyReader;
use Stewart\Mqtt\Packet\PacketBytes;
use Stewart\Mqtt\Packet\PacketDecoder;
use Stewart\Mqtt\Packet\PacketReader;
use Stewart\Mqtt\Packet\PingRespPacket;
use Stewart\Mqtt\Packet\PubAckPacket;
use Stewart\Mqtt\Packet\PublishPacket;
use Stewart\Mqtt\Packet\SubAckPacket;
use Stewart\Mqtt\Packet\UnsubAckPacket;
use Stewart\Testing\Exception\AssertsReason;

#[CoversClass(PacketDecoder::class)]
#[CoversClass(PacketReader::class)]
#[CoversClass(PacketBodyReader::class)]
#[CoversClass(MqttClientException::class)]
final class PacketDecoderTest extends TestCase
{
    use AssertsReason;

    public function testConnAckCarriesReturnCode(): void
    {
        $accepted = self::readPacket('20020100');
        $refused = self::readPacket('20020004');

        self::assertInstanceOf(ConnAckPacket::class, $accepted);
        self::assertTrue($accepted->sessionPresent);
        self::assertSame(ConnectReturnCode::Accepted, $accepted->returnCode);
        self::assertInstanceOf(ConnAckPacket::class, $refused);
        self::assertSame(ConnectReturnCode::CredentialsInvalid, $refused->returnCode);
    }

    public function testRetainedPublishKeepsQosAndPacketId(): void
    {
        $packet = self::readPacket('3309' . '0003612f62' . '000a' . '6869');

        self::assertInstanceOf(PublishPacket::class, $packet);
        self::assertSame(10, $packet->packetId);
        self::assertSame('a/b', $packet->message->topic);
        self::assertSame('hi', $packet->message->payload);
        self::assertSame(MqttQos::AtLeastOnce, $packet->message->qos);
        self::assertTrue($packet->message->retain);
    }

    public function testAcknowledgementsCarryTheirPacketId(): void
    {
        $pubAck = self::readPacket('4002000a');
        $unsubAck = self::readPacket('b002000b');
        $subAck = self::readPacket('9003000c01');

        self::assertInstanceOf(PubAckPacket::class, $pubAck);
        self::assertSame(10, $pubAck->packetId);
        self::assertInstanceOf(UnsubAckPacket::class, $unsubAck);
        self::assertSame(11, $unsubAck->packetId);
        self::assertInstanceOf(SubAckPacket::class, $subAck);
        self::assertSame(MqttQos::AtLeastOnce, $subAck->grantedQos);
        self::assertInstanceOf(PingRespPacket::class, self::readPacket('d000'));
    }

    public function testRefusedSubscriptionHasNoGrantedQos(): void
    {
        $packet = self::readPacket('90030001' . '80');

        self::assertInstanceOf(SubAckPacket::class, $packet);
        self::assertNull($packet->grantedQos);
    }

    public function testMultiByteLengthIsReassembled(): void
    {
        $payload = str_repeat('x', 314);
        $packet = self::readPacket('30c102' . '0005' . bin2hex('topic') . bin2hex($payload));

        self::assertInstanceOf(PublishPacket::class, $packet);
        self::assertSame($payload, $packet->message->payload);
    }

    #[DataProvider('provideMalformedPackets')]
    public function testMalformedPacketIsRejected(string $hex): void
    {
        self::assertThrowsReason(MqttClientError::PacketMalformed, static fn() => self::readPacket($hex));
    }

    /** @return iterable<string, array{string}> */
    public static function provideMalformedPackets(): iterable
    {
        yield 'topic longer than the body' => ['3003' . '0009' . '61'];
        yield 'flags on an acknowledgement' => ['4102000a'];
        yield 'trailing bytes after an acknowledgement' => ['4003000a00'];
        yield 'quality of service 2' => ['3406' . '0001' . '61' . '0001' . '68'];
        yield 'unknown return code' => ['20020009'];
        yield 'remaining length over four digits' => ['30ffffffff01'];
        yield 'packet over the size limit' => ['30' . bin2hex(PacketBytes::encodeRemainingLength(16 * 1024 * 1024 + 1))];
    }

    public function testClientPacketFromServerIsUnexpected(): void
    {
        self::assertThrowsReason(MqttClientError::PacketUnexpected, static fn() => self::readPacket('8208000100036123' . '2301'));
    }

    private static function readPacket(string $hex): object
    {
        return new PacketReader(new PacketDecoder())->readPacket(new BufferedReader(new ReadableBuffer((string) hex2bin($hex))));
    }
}
