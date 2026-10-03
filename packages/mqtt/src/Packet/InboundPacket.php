<?php

declare(strict_types=1);

namespace Stewart\Mqtt\Packet;

use Stewart\Mqtt\Exception\MqttClientException;

interface InboundPacket
{
    public function getPacketType(): PacketType;

    /** @throws MqttClientException */
    public function applyTo(InboundPacketHandler $handler): void;
}
