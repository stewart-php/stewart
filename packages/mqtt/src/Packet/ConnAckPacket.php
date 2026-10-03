<?php

declare(strict_types=1);

namespace Stewart\Mqtt\Packet;

final readonly class ConnAckPacket implements InboundPacket
{
    public function __construct(
        public bool $sessionPresent,
        public ConnectReturnCode $returnCode,
    ) {}

    public function getPacketType(): PacketType
    {
        return PacketType::ConnAck;
    }

    public function applyTo(InboundPacketHandler $handler): void
    {
        $handler->handleConnAck($this);
    }
}
