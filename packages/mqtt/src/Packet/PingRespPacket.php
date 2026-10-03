<?php

declare(strict_types=1);

namespace Stewart\Mqtt\Packet;

final readonly class PingRespPacket implements InboundPacket
{
    public function getPacketType(): PacketType
    {
        return PacketType::PingResp;
    }

    public function applyTo(InboundPacketHandler $handler): void
    {
        $handler->handlePingResp($this);
    }
}
