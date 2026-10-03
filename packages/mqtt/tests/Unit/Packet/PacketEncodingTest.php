<?php

declare(strict_types=1);

namespace Stewart\Mqtt\Tests\Unit\Packet;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Mqtt\MqttMessage;
use Stewart\Contracts\Mqtt\MqttQos;
use Stewart\Contracts\Time\Duration;
use Stewart\Mqtt\Packet\ConnectPacket;
use Stewart\Mqtt\Packet\DisconnectPacket;
use Stewart\Mqtt\Packet\OutboundPacket;
use Stewart\Mqtt\Packet\PacketBytes;
use Stewart\Mqtt\Packet\PingReqPacket;
use Stewart\Mqtt\Packet\PubAckPacket;
use Stewart\Mqtt\Packet\PublishPacket;
use Stewart\Mqtt\Packet\SubscribePacket;
use Stewart\Mqtt\Packet\UnsubscribePacket;

#[CoversClass(ConnectPacket::class)]
#[CoversClass(PublishPacket::class)]
#[CoversClass(PubAckPacket::class)]
#[CoversClass(SubscribePacket::class)]
#[CoversClass(UnsubscribePacket::class)]
#[CoversClass(PingReqPacket::class)]
#[CoversClass(DisconnectPacket::class)]
#[CoversClass(PacketBytes::class)]
final class PacketEncodingTest extends TestCase
{
    #[DataProvider('provideEncodedPackets')]
    public function testPacketEncodesToSpecBytes(OutboundPacket $packet, string $expectedHex): void
    {
        self::assertSame($expectedHex, bin2hex($packet->encodePacket()));
    }

    /** @return iterable<string, array{OutboundPacket, string}> */
    public static function provideEncodedPackets(): iterable
    {
        yield 'connect with will and credentials' => [
            new ConnectPacket('stewart', Duration::seconds(30), 'u', 'p', new MqttMessage('st', 'off', MqttQos::AtLeastOnce, true)),
            '1022' . '00044d51545404ee001e' . '000773746577617274' . '00027374' . '00036f6666' . '000175' . '000170',
        ];
        yield 'connect without credentials' => [
            new ConnectPacket('c', Duration::seconds(5)),
            '100d' . '00044d515454040200050001' . '63',
        ];
        yield 'password without username is left out' => [
            new ConnectPacket('c', Duration::seconds(5), null, 'p'),
            '100d' . '00044d515454040200050001' . '63',
        ];
        yield 'publish at most once' => [new PublishPacket(new MqttMessage('a/b', 'hi')), '3007' . '0003612f62' . '6869'];
        yield 'retained publish at least once' => [
            new PublishPacket(new MqttMessage('a/b', 'hi', MqttQos::AtLeastOnce, true), 10),
            '3309' . '0003612f62' . '000a' . '6869',
        ];
        yield 'puback' => [new PubAckPacket(10), '4002000a'];
        yield 'subscribe' => [new SubscribePacket(1, 'a/#', MqttQos::AtLeastOnce), '8208' . '0001' . '0003612f23' . '01'];
        yield 'unsubscribe' => [new UnsubscribePacket(2, 'a/#'), 'a207' . '0002' . '0003612f23'];
        yield 'pingreq' => [new PingReqPacket(), 'c000'];
        yield 'disconnect' => [new DisconnectPacket(), 'e000'];
    }

    public function testLongBodyUsesMultiByteLength(): void
    {
        self::assertSame('c102', bin2hex(PacketBytes::encodeRemainingLength(321)));
        self::assertSame('ffffff7f', bin2hex(PacketBytes::encodeRemainingLength(PacketBytes::MAX_REMAINING_LENGTH)));
    }
}
