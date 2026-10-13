<?php

declare(strict_types=1);

namespace Stewart\Mqtt\Packet;

final readonly class PubAckPacket implements InboundPacket
{
    public function __construct(public int $packetId) {}

    public function getPacketType(): PacketType
    {
        return PacketType::PubAck;
    }
}
