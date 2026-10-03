<?php

declare(strict_types=1);

namespace Stewart\Mqtt\Packet;

enum PacketType: int
{
    case Connect = 1;
    case ConnAck = 2;
    case Publish = 3;
    case PubAck = 4;
    case Subscribe = 8;
    case SubAck = 9;
    case Unsubscribe = 10;
    case UnsubAck = 11;
    case PingReq = 12;
    case PingResp = 13;
    case Disconnect = 14;

    public function isSentByServer(): bool
    {
        return match ($this) {
            self::ConnAck, self::Publish, self::PubAck, self::SubAck, self::UnsubAck, self::PingResp => true,
            self::Connect, self::Subscribe, self::Unsubscribe, self::PingReq, self::Disconnect => false,
        };
    }
}
