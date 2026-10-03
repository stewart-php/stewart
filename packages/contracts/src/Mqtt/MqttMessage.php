<?php

declare(strict_types=1);

namespace Stewart\Contracts\Mqtt;

use JsonException;
use Stewart\Contracts\Exception\MqttException;

final readonly class MqttMessage
{
    private const int MAX_TOPIC_BYTES = 65535;

    public function __construct(
        public string $topic,
        public string $payload,
        public MqttQos $qos = MqttQos::AtMostOnce,
        public bool $retain = false,
    ) {}

    /**
     * @param string|array<array-key, mixed> $payload
     * @throws MqttException
     */
    public static function createForPublish(string $topic, string|array $payload, MqttQos $qos, bool $retain): self
    {
        if ($topic === '' || \strlen($topic) > self::MAX_TOPIC_BYTES || strpbrk($topic, "+#\0") !== false) {
            throw MqttException::topicInvalid($topic);
        }

        if (\is_string($payload)) {
            return new self($topic, $payload, $qos, $retain);
        }

        try {
            return new self($topic, json_encode($payload, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE), $qos, $retain);
        } catch (JsonException $e) {
            throw MqttException::payloadUnencodable($topic, $e);
        }
    }

    /** @throws MqttException */
    public function decodeJsonPayload(): mixed
    {
        try {
            return json_decode($this->payload, true, flags: \JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw MqttException::payloadNotJson($this->topic, $e);
        }
    }
}
