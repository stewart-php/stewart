<?php

declare(strict_types=1);

namespace Stewart\Mqtt\Packet;

interface InboundPacket
{
    public function getPacketType(): PacketType;
}
