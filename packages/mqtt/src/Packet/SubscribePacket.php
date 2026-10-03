<?php

declare(strict_types=1);

namespace Stewart\Mqtt\Packet;

use Stewart\Contracts\Mqtt\MqttQos;

final readonly class SubscribePacket implements OutboundPacket
{
    private const int REQUIRED_FLAGS = 0x02;

    public function __construct(
        public int $packetId,
        public string $topicFilter,
        public MqttQos $maximumQos,
    ) {}

    public function encodePacket(): string
    {
        $body = PacketBytes::encodeUint16($this->packetId) . PacketBytes::encodeString($this->topicFilter) . \chr($this->maximumQos->value);

        return PacketBytes::assemblePacket(PacketType::Subscribe, self::REQUIRED_FLAGS, $body);
    }
}
