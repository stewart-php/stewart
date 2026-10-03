<?php

declare(strict_types=1);

namespace Stewart\Mqtt\Packet;

use Stewart\Contracts\Mqtt\MqttQos;

final readonly class SubAckPacket implements InboundPacket
{
    public function __construct(
        public int $packetId,
        public ?MqttQos $grantedQos,
    ) {}

    public function getPacketType(): PacketType
    {
        return PacketType::SubAck;
    }

    public function applyTo(InboundPacketHandler $handler): void
    {
        $handler->handleSubAck($this);
    }
}
