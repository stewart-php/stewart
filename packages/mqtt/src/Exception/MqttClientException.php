<?php

declare(strict_types=1);

namespace Stewart\Mqtt\Exception;

use Stewart\Contracts\Exception\StewartException;

/** @extends StewartException<MqttClientError> */
final class MqttClientException extends StewartException
{
    public static function packetMalformed(string $packetType): self
    {
        return self::createForReason(MqttClientError::PacketMalformed, packetType: $packetType);
    }

    public static function packetUnexpected(string $packetType): self
    {
        return self::createForReason(MqttClientError::PacketUnexpected, packetType: $packetType);
    }
}
