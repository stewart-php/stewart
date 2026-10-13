<?php

declare(strict_types=1);

namespace Stewart\Mqtt\Packet;

use Amp\ByteStream\BufferedReader;
use Amp\Cancellation;
use Stewart\Contracts\Mqtt\MqttMessage;
use Stewart\Contracts\Mqtt\MqttQos;
use Stewart\Mqtt\Exception\MqttClientException;
use Throwable;

final readonly class PacketReader
{
    private const int MAX_LENGTH_DIGITS = 4;

    private const int LENGTH_DIGIT_BASE = 128;

    private const int MAX_INBOUND_PACKET_BYTES = 16 * 1024 * 1024;

    private const int TYPE_SHIFT = 4;

    private const int FLAGS_MASK = 0x0F;

    private const int PUBLISH_QOS_SHIFT = 1;

    private const int PUBLISH_QOS_MASK = 0x03;

    private const int PUBLISH_RETAIN_FLAG = 0x01;

    private const int SUBSCRIPTION_FAILED = 0x80;

    private const int SESSION_PRESENT_FLAG = 0x01;

    /** @throws MqttClientException|Throwable */
    public function readPacket(BufferedReader $reader, ?Cancellation $cancellation = null): InboundPacket
    {
        $fixedHeader = \ord($reader->readLength(1, $cancellation));
        $remainingLength = $this->readRemainingLength($reader, $cancellation);

        if ($remainingLength > self::MAX_INBOUND_PACKET_BYTES) {
            throw MqttClientException::packetMalformed($remainingLength . '-byte');
        }

        return $this->decodePacket($fixedHeader, $remainingLength > 0 ? $reader->readLength($remainingLength, $cancellation) : '');
    }

    /** @throws MqttClientException|Throwable */
    private function readRemainingLength(BufferedReader $reader, ?Cancellation $cancellation): int
    {
        $length = 0;
        $multiplier = 1;

        for ($digits = 0; $digits < self::MAX_LENGTH_DIGITS; ++$digits) {
            $digit = \ord($reader->readLength(1, $cancellation));
            $length += ($digit % self::LENGTH_DIGIT_BASE) * $multiplier;

            if ($digit < self::LENGTH_DIGIT_BASE) {
                return $length;
            }

            $multiplier *= self::LENGTH_DIGIT_BASE;
        }

        throw MqttClientException::packetMalformed('remaining length');
    }

    /** @throws MqttClientException */
    private function decodePacket(int $fixedHeader, string $body): InboundPacket
    {
        $type = PacketType::tryFrom($fixedHeader >> self::TYPE_SHIFT)
            ?? throw MqttClientException::packetUnexpected('type ' . ($fixedHeader >> self::TYPE_SHIFT));
        $flags = $fixedHeader & self::FLAGS_MASK;
        $bodyReader = new PacketBodyReader($type, $body);

        if (!$type->isSentByServer()) {
            throw MqttClientException::packetUnexpected($type->name);
        }

        if ($type === PacketType::Publish) {
            return $this->decodePublish($flags, $bodyReader);
        }

        if ($flags !== 0) {
            throw $bodyReader->createMalformedException();
        }

        $packet = match ($type) {
            PacketType::ConnAck => $this->decodeConnAck($bodyReader),
            PacketType::PubAck => new PubAckPacket($bodyReader->readUint16()),
            PacketType::SubAck => $this->decodeSubAck($bodyReader),
            PacketType::UnsubAck => new UnsubAckPacket($bodyReader->readUint16()),
            PacketType::PingResp => new PingRespPacket(),
            default => throw MqttClientException::packetUnexpected($type->name),
        };

        $bodyReader->assertFullyRead();

        return $packet;
    }

    /** @throws MqttClientException */
    private function decodePublish(int $flags, PacketBodyReader $body): PublishPacket
    {
        $qos = MqttQos::tryFrom(($flags >> self::PUBLISH_QOS_SHIFT) & self::PUBLISH_QOS_MASK) ?? throw $body->createMalformedException();
        $topic = $body->readString();
        $packetId = $qos === MqttQos::AtMostOnce ? null : $body->readUint16();

        return new PublishPacket(new MqttMessage($topic, $body->readRemainder(), $qos, ($flags & self::PUBLISH_RETAIN_FLAG) !== 0), $packetId);
    }

    /** @throws MqttClientException */
    private function decodeConnAck(PacketBodyReader $body): ConnAckPacket
    {
        $sessionPresent = ($body->readUint8() & self::SESSION_PRESENT_FLAG) !== 0;

        return new ConnAckPacket($sessionPresent, ConnectReturnCode::tryFrom($body->readUint8()) ?? throw $body->createMalformedException());
    }

    /** @throws MqttClientException */
    private function decodeSubAck(PacketBodyReader $body): SubAckPacket
    {
        $packetId = $body->readUint16();
        $code = $body->readUint8();

        if ($code === self::SUBSCRIPTION_FAILED) {
            return new SubAckPacket($packetId, null);
        }

        return new SubAckPacket($packetId, MqttQos::tryFrom($code) ?? throw $body->createMalformedException());
    }
}
