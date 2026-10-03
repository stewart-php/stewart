<?php

declare(strict_types=1);

namespace Stewart\Mqtt\Packet;

final readonly class UnsubscribePacket implements OutboundPacket
{
    private const int REQUIRED_FLAGS = 0x02;

    public function __construct(
        public int $packetId,
        public string $topicFilter,
    ) {}

    public function encodePacket(): string
    {
        return PacketBytes::assemblePacket(
            PacketType::Unsubscribe,
            self::REQUIRED_FLAGS,
            PacketBytes::encodeUint16($this->packetId) . PacketBytes::encodeString($this->topicFilter),
        );
    }
}
