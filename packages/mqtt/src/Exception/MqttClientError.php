<?php

declare(strict_types=1);

namespace Stewart\Mqtt\Exception;

use Stewart\Contracts\Exception\ExceptionReason;

enum MqttClientError: string implements ExceptionReason
{
    case ConnectionRefused = 'mqtt_connection_refused';
    case ConnectionLost = 'mqtt_connection_lost';
    case ConnectionClosed = 'mqtt_connection_closed';
    case KeepaliveTimedOut = 'mqtt_keepalive_timed_out';
    case PacketMalformed = 'mqtt_packet_malformed';
    case PacketUnexpected = 'mqtt_packet_unexpected';
    case PacketIdsExhausted = 'mqtt_packet_ids_exhausted';

    public function messageTemplate(): string
    {
        return match ($this) {
            self::ConnectionRefused => 'The MQTT server refused the connection: {refusal}.',
            self::ConnectionLost => 'The MQTT connection was lost: {cause}',
            self::ConnectionClosed => 'The MQTT connection is closed.',
            self::KeepaliveTimedOut => 'The MQTT server left a keepalive ping unanswered for {keepalive}.',
            self::PacketMalformed => 'The MQTT server sent a malformed {packetType} packet.',
            self::PacketUnexpected => 'The MQTT server sent an unexpected {packetType} packet.',
            self::PacketIdsExhausted => 'Every MQTT packet identifier is in flight.',
        };
    }
}
