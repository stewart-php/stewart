<?php

declare(strict_types=1);

namespace Stewart\Mqtt\Exception;

use Stewart\Contracts\Exception\StewartException;
use Stewart\Contracts\Time\Duration;
use Throwable;

/** @extends StewartException<MqttClientError> */
final class MqttClientException extends StewartException
{
    public static function connectionRefused(string $refusal): self
    {
        return self::createForReason(MqttClientError::ConnectionRefused, ['refusal' => $refusal]);
    }

    public static function connectionLost(Throwable $previous): self
    {
        return self::createForReason(MqttClientError::ConnectionLost, [], $previous);
    }

    public static function connectionClosed(): self
    {
        return self::createForReason(MqttClientError::ConnectionClosed);
    }

    public static function keepaliveTimedOut(Duration $keepalive): self
    {
        return self::createForReason(MqttClientError::KeepaliveTimedOut, ['keepalive' => (string) $keepalive]);
    }

    public static function packetMalformed(string $packetType): self
    {
        return self::createForReason(MqttClientError::PacketMalformed, ['packetType' => $packetType]);
    }

    public static function packetUnexpected(string $packetType): self
    {
        return self::createForReason(MqttClientError::PacketUnexpected, ['packetType' => $packetType]);
    }

    public static function packetIdsExhausted(): self
    {
        return self::createForReason(MqttClientError::PacketIdsExhausted);
    }
}
