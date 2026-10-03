<?php

declare(strict_types=1);

namespace Stewart\Mqtt\Packet;

final readonly class PubAckPacket implements InboundPacket, OutboundPacket
{
    public function __construct(public int $packetId) {}

    public function encodePacket(): string
    {
        return PacketBytes::assemblePacket(PacketType::PubAck, 0, PacketBytes::encodeUint16($this->packetId));
    }

    public function getPacketType(): PacketType
    {
        return PacketType::PubAck;
    }

    public function applyTo(InboundPacketHandler $handler): void
    {
        $handler->handlePubAck($this);
    }
}
