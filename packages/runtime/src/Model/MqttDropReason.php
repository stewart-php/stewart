<?php

declare(strict_types=1);

namespace Stewart\Runtime\Model;

enum MqttDropReason: string
{
    case Disconnected = 'disconnected';
    case SendFailed = 'send_failed';
    case BufferFull = 'buffer_full';
    case GaveUp = 'gave_up';

    public function describeDrop(): string
    {
        return match ($this) {
            self::Disconnected => 'MQTT message dropped while the server is unreachable',
            self::SendFailed => 'MQTT message dropped; sending it failed',
            self::BufferFull => 'MQTT outbound buffer is full; dropped the oldest message',
            self::GaveUp => 'MQTT message dropped; the server never acknowledged it',
        };
    }
}
