<?php

declare(strict_types=1);

namespace Stewart\Mqtt\Packet;

final readonly class UnsubAckPacket implements InboundPacket
{
    public function __construct(public int $packetId) {}

    public function getPacketType(): PacketType
    {
        return PacketType::UnsubAck;
    }

    public function applyTo(InboundPacketHandler $handler): void
    {
        $handler->handleUnsubAck($this);
    }
}
