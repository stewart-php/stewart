<?php

declare(strict_types=1);

namespace Stewart\Mqtt\Tests\Unit\Packet;

use Closure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Mqtt\MqttMessage;
use Stewart\Contracts\Mqtt\MqttQos;
use Stewart\Contracts\Time\Duration;
use Stewart\Mqtt\Packet\PacketBytes;
use Stewart\Mqtt\Packet\PacketEncoder;

#[CoversClass(PacketEncoder::class)]
#[CoversClass(PacketBytes::class)]
final class PacketEncoderTest extends TestCase
{
    /** @param Closure(PacketEncoder): string $encode */
    #[DataProvider('provideEncodedPackets')]
    public function testPacketEncodesToSpecBytes(Closure $encode, string $expectedHex): void
    {
        self::assertSame($expectedHex, bin2hex($encode(new PacketEncoder())));
    }

    /** @return iterable<string, array{Closure(PacketEncoder): string, string}> */
    public static function provideEncodedPackets(): iterable
    {
        yield 'connect with will and credentials' => [
            static fn(PacketEncoder $encoder): string => $encoder->encodeConnect('stewart', Duration::seconds(30), 'u', 'p', new MqttMessage('st', 'off', MqttQos::AtLeastOnce, true)),
            '1022' . '00044d51545404ee001e' . '000773746577617274' . '00027374' . '00036f6666' . '000175' . '000170',
        ];
        yield 'connect without credentials' => [
            static fn(PacketEncoder $encoder): string => $encoder->encodeConnect('c', Duration::seconds(5)),
            '100d' . '00044d515454040200050001' . '63',
        ];
        yield 'password without username is left out' => [
            static fn(PacketEncoder $encoder): string => $encoder->encodeConnect('c', Duration::seconds(5), null, 'p'),
            '100d' . '00044d515454040200050001' . '63',
        ];
        yield 'publish at most once' => [
            static fn(PacketEncoder $encoder): string => $encoder->encodePublish(new MqttMessage('a/b', 'hi')),
            '3007' . '0003612f62' . '6869',
        ];
        yield 'retained publish at least once' => [
            static fn(PacketEncoder $encoder): string => $encoder->encodePublish(new MqttMessage('a/b', 'hi', MqttQos::AtLeastOnce, true), 10),
            '3309' . '0003612f62' . '000a' . '6869',
        ];
        yield 'puback' => [static fn(PacketEncoder $encoder): string => $encoder->encodePubAck(10), '4002000a'];
        yield 'subscribe' => [
            static fn(PacketEncoder $encoder): string => $encoder->encodeSubscribe(1, 'a/#', MqttQos::AtLeastOnce),
            '8208' . '0001' . '0003612f23' . '01',
        ];
        yield 'unsubscribe' => [static fn(PacketEncoder $encoder): string => $encoder->encodeUnsubscribe(2, 'a/#'), 'a207' . '0002' . '0003612f23'];
        yield 'pingreq' => [static fn(PacketEncoder $encoder): string => $encoder->encodePingRequest(), 'c000'];
        yield 'disconnect' => [static fn(PacketEncoder $encoder): string => $encoder->encodeDisconnect(), 'e000'];
    }

    public function testLongBodyUsesMultiByteLength(): void
    {
        self::assertSame('c102', bin2hex(PacketBytes::encodeRemainingLength(321)));
        self::assertSame('ffffff7f', bin2hex(PacketBytes::encodeRemainingLength(PacketBytes::MAX_REMAINING_LENGTH)));
    }
}
