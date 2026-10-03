<?php

declare(strict_types=1);

namespace Stewart\Contracts\Exception;

use Throwable;

/** @extends StewartException<MqttError> */
final class MqttException extends StewartException
{
    public static function topicInvalid(string $topic): self
    {
        return self::createForReason(MqttError::TopicInvalid, ['topic' => $topic]);
    }

    public static function payloadUnencodable(string $topic, Throwable $previous): self
    {
        return self::createForReason(MqttError::PayloadUnencodable, ['topic' => $topic], $previous);
    }

    public static function notConfigured(): self
    {
        return self::createForReason(MqttError::NotConfigured);
    }

    public static function publishFailed(string $topic, Throwable $previous): self
    {
        return self::createForReason(MqttError::PublishFailed, ['topic' => $topic], $previous);
    }

    public static function payloadNotJson(string $topic, Throwable $previous): self
    {
        return self::createForReason(MqttError::PayloadNotJson, ['topic' => $topic], $previous);
    }
}
