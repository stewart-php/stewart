<?php

declare(strict_types=1);

namespace Stewart\Mqtt\Packet;

interface OutboundPacket
{
    public function encodePacket(): string;
}
