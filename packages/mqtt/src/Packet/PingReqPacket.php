<?php

declare(strict_types=1);

namespace Stewart\Mqtt\Packet;

final readonly class PingReqPacket implements OutboundPacket
{
    public function encodePacket(): string
    {
        return PacketBytes::assemblePacket(PacketType::PingReq, 0, '');
    }
}
