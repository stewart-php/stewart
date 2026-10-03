<?php

declare(strict_types=1);

namespace Stewart\Mqtt\Packet;

use Stewart\Contracts\Mqtt\MqttMessage;
use Stewart\Contracts\Mqtt\MqttQos;
use Stewart\Mqtt\Exception\MqttClientException;

final readonly class PacketDecoder
{
    private const int TYPE_SHIFT = 4;

    private const int FLAGS_MASK = 0x0F;

    private const int PUBLISH_QOS_SHIFT = 1;

    private const int PUBLISH_QOS_MASK = 0x03;

    private const int PUBLISH_RETAIN_FLAG = 0x01;

    private const int SUBSCRIPTION_FAILED = 0x80;

    private const int SESSION_PRESENT_FLAG = 0x01;

    /** @throws MqttClientException */
    public function decodePacket(int $fixedHeader, string $body): InboundPacket
    {
        $type = PacketType::tryFrom($fixedHeader >> self::TYPE_SHIFT)
            ?? throw MqttClientException::packetUnexpected('type ' . ($fixedHeader >> self::TYPE_SHIFT));
        $flags = $fixedHeader & self::FLAGS_MASK;
        $reader = new PacketBodyReader($type, $body);

        if (!$type->isSentByServer()) {
            throw MqttClientException::packetUnexpected($type->name);
        }

        if ($type === PacketType::Publish) {
            return $this->decodePublish($flags, $reader);
        }

        if ($flags !== 0) {
            throw $reader->createMalformedException();
        }

        $packet = match ($type) {
            PacketType::ConnAck => $this->decodeConnAck($reader),
            PacketType::PubAck => new PubAckPacket($reader->readUint16()),
            PacketType::SubAck => $this->decodeSubAck($reader),
            PacketType::UnsubAck => new UnsubAckPacket($reader->readUint16()),
            PacketType::PingResp => new PingRespPacket(),
            default => throw MqttClientException::packetUnexpected($type->name),
        };
        $reader->assertFullyRead();

        return $packet;
    }

    /** @throws MqttClientException */
    private function decodePublish(int $flags, PacketBodyReader $reader): PublishPacket
    {
        $qos = MqttQos::tryFrom(($flags >> self::PUBLISH_QOS_SHIFT) & self::PUBLISH_QOS_MASK) ?? throw $reader->createMalformedException();
        $topic = $reader->readString();
        $packetId = $qos === MqttQos::AtMostOnce ? null : $reader->readUint16();

        return new PublishPacket(new MqttMessage($topic, $reader->readRemainder(), $qos, ($flags & self::PUBLISH_RETAIN_FLAG) !== 0), $packetId);
    }

    /** @throws MqttClientException */
    private function decodeConnAck(PacketBodyReader $reader): ConnAckPacket
    {
        $sessionPresent = ($reader->readUint8() & self::SESSION_PRESENT_FLAG) !== 0;

        return new ConnAckPacket($sessionPresent, ConnectReturnCode::tryFrom($reader->readUint8()) ?? throw $reader->createMalformedException());
    }

    /** @throws MqttClientException */
    private function decodeSubAck(PacketBodyReader $reader): SubAckPacket
    {
        $packetId = $reader->readUint16();
        $code = $reader->readUint8();

        if ($code === self::SUBSCRIPTION_FAILED) {
            return new SubAckPacket($packetId, null);
        }

        return new SubAckPacket($packetId, MqttQos::tryFrom($code) ?? throw $reader->createMalformedException());
    }
}
