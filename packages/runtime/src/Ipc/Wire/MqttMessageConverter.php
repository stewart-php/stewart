<?php

declare(strict_types=1);

namespace Stewart\Runtime\Ipc\Wire;

use Stewart\Contracts\Exception\JsonShapeException;
use Stewart\Contracts\Mqtt\MqttMessage;
use Stewart\Contracts\Mqtt\MqttQos;
use Stewart\Runtime\Json\ValueConverter;
use Stewart\Support\Json\JsonShape;

// Payloads are bytes and IPC frames are JSON, so the payload travels base64-encoded.
final readonly class MqttMessageConverter implements ValueConverter
{
    public function handledClass(): string
    {
        return MqttMessage::class;
    }

    public function keySuffix(): string
    {
        return '';
    }

    /** @return array{topic: string, payload_base64: string, qos: int, retain: bool} */
    public function encodeValue(object $value): array
    {
        \assert($value instanceof MqttMessage);

        return [
            'topic' => $value->topic,
            'payload_base64' => base64_encode($value->payload),
            'qos' => $value->qos->value,
            'retain' => $value->retain,
        ];
    }

    /** @throws JsonShapeException */
    public function decodeValue(mixed $value, string $path): MqttMessage
    {
        if (!\is_array($value)) {
            throw JsonShapeException::wrongType($path, 'an object', get_debug_type($value));
        }

        $payload = base64_decode(JsonShape::requireString($value, 'payload_base64'), true);
        $qos = \is_int($value['qos'] ?? null) ? MqttQos::tryFrom($value['qos']) : null;
        $retain = $value['retain'] ?? null;

        if ($payload === false) {
            throw JsonShapeException::unexpectedValue($path . '.payload_base64', 'base64 text', $value['payload_base64']);
        }

        if ($qos === null) {
            throw JsonShapeException::unexpectedValue($path . '.qos', '0 or 1', $value['qos'] ?? null);
        }

        if (!\is_bool($retain)) {
            throw JsonShapeException::wrongType($path . '.retain', 'a boolean', get_debug_type($retain));
        }

        return new MqttMessage(JsonShape::requireString($value, 'topic'), $payload, $qos, $retain);
    }
}
