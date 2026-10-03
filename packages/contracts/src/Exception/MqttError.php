<?php

declare(strict_types=1);

namespace Stewart\Contracts\Exception;

enum MqttError: string implements ExceptionReason
{
    case TopicInvalid = 'mqtt_topic_invalid';
    case PayloadUnencodable = 'mqtt_payload_unencodable';
    case PayloadNotJson = 'mqtt_payload_not_json';
    case NotConfigured = 'mqtt_not_configured';
    case PublishFailed = 'mqtt_publish_failed';

    public function messageTemplate(): string
    {
        return match ($this) {
            self::TopicInvalid => 'MQTT topic {topic} must be non-empty and free of +, # and NUL characters.',
            self::PayloadUnencodable => 'MQTT payload for {topic} cannot be encoded as JSON: {cause}',
            self::PayloadNotJson => 'MQTT payload on {topic} is not valid JSON: {cause}',
            self::NotConfigured => 'MQTT is not configured; set mqtt.url in stewart.yaml or STEWART_MQTT__URL in .env.',
            self::PublishFailed => 'Could not hand the MQTT message for {topic} to the broker: {cause}',
        };
    }
}
