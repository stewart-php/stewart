<?php

declare(strict_types=1);

namespace Stewart\Mqtt\Packet;

use Stewart\Contracts\Mqtt\MqttMessage;

final readonly class PublishPacket implements InboundPacket
{
    public function __construct(
        public MqttMessage $message,
        public ?int $packetId = null,
    ) {}

    public function getPacketType(): PacketType
    {
        return PacketType::Publish;
    }
}
