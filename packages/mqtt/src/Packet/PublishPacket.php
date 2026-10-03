<?php

declare(strict_types=1);

namespace Stewart\Mqtt\Packet;

use Stewart\Contracts\Mqtt\MqttMessage;
use Stewart\Contracts\Mqtt\MqttQos;

final readonly class PublishPacket implements InboundPacket, OutboundPacket
{
    private const int RETAIN_FLAG = 0x01;

    private const int QOS_SHIFT = 1;

    public function __construct(
        public MqttMessage $message,
        public ?int $packetId = null,
    ) {}

    public function encodePacket(): string
    {
        $flags = ($this->message->qos->value << self::QOS_SHIFT) | ($this->message->retain ? self::RETAIN_FLAG : 0);
        $body = PacketBytes::encodeString($this->message->topic);

        if ($this->message->qos !== MqttQos::AtMostOnce) {
            $body .= PacketBytes::encodeUint16($this->packetId ?? 0);
        }

        return PacketBytes::assemblePacket(PacketType::Publish, $flags, $body . $this->message->payload);
    }

    public function getPacketType(): PacketType
    {
        return PacketType::Publish;
    }

    public function applyTo(InboundPacketHandler $handler): void
    {
        $handler->handlePublish($this);
    }
}
