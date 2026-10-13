<?php

declare(strict_types=1);

namespace Stewart\Mqtt\Packet;

use SensitiveParameter;
use Stewart\Contracts\Mqtt\MqttMessage;
use Stewart\Contracts\Mqtt\MqttQos;
use Stewart\Contracts\Time\Duration;

final readonly class PacketEncoder
{
    private const string PROTOCOL_NAME = 'MQTT';

    private const int PROTOCOL_LEVEL = 4;

    private const int CLEAN_SESSION_FLAG = 0x02;

    private const int WILL_FLAG = 0x04;

    private const int WILL_QOS_SHIFT = 3;

    private const int WILL_RETAIN_FLAG = 0x20;

    private const int PASSWORD_FLAG = 0x40;

    private const int USERNAME_FLAG = 0x80;

    private const int MAX_KEEPALIVE_SECONDS = 65535;

    private const int PUBLISH_RETAIN_FLAG = 0x01;

    private const int PUBLISH_QOS_SHIFT = 1;

    private const int SUBSCRIPTION_REQUIRED_FLAGS = 0x02;

    public function encodeConnect(
        string $clientId,
        Duration $keepalive,
        ?string $username = null,
        #[SensitiveParameter]
        ?string $password = null,
        ?MqttMessage $will = null,
    ): string {
        $flags = self::CLEAN_SESSION_FLAG;
        $payload = PacketBytes::encodeString($clientId);

        if ($will !== null) {
            $flags |= self::WILL_FLAG | ($will->qos->value << self::WILL_QOS_SHIFT) | ($will->retain ? self::WILL_RETAIN_FLAG : 0);
            $payload .= PacketBytes::encodeString($will->topic) . PacketBytes::encodeString($will->payload);
        }

        // MQTT 3.1.1 allows a password only together with a username.
        if ($username !== null) {
            $flags |= self::USERNAME_FLAG;
            $payload .= PacketBytes::encodeString($username);

            if ($password !== null) {
                $flags |= self::PASSWORD_FLAG;
                $payload .= PacketBytes::encodeString($password);
            }
        }

        $variableHeader = PacketBytes::encodeString(self::PROTOCOL_NAME)
            . \chr(self::PROTOCOL_LEVEL)
            . \chr($flags)
            . PacketBytes::encodeUint16(min(self::MAX_KEEPALIVE_SECONDS, (int) ceil($keepalive->toSeconds())));

        return PacketBytes::assemblePacket(PacketType::Connect, 0, $variableHeader . $payload);
    }

    public function encodePublish(MqttMessage $message, ?int $packetId = null): string
    {
        $flags = ($message->qos->value << self::PUBLISH_QOS_SHIFT) | ($message->retain ? self::PUBLISH_RETAIN_FLAG : 0);
        $body = PacketBytes::encodeString($message->topic);

        if ($message->qos !== MqttQos::AtMostOnce) {
            $body .= PacketBytes::encodeUint16($packetId ?? 0);
        }

        return PacketBytes::assemblePacket(PacketType::Publish, $flags, $body . $message->payload);
    }

    public function encodePubAck(int $packetId): string
    {
        return PacketBytes::assemblePacket(PacketType::PubAck, 0, PacketBytes::encodeUint16($packetId));
    }

    public function encodeSubscribe(int $packetId, string $topicFilter, MqttQos $maximumQos): string
    {
        $body = PacketBytes::encodeUint16($packetId) . PacketBytes::encodeString($topicFilter) . \chr($maximumQos->value);

        return PacketBytes::assemblePacket(PacketType::Subscribe, self::SUBSCRIPTION_REQUIRED_FLAGS, $body);
    }

    public function encodeUnsubscribe(int $packetId, string $topicFilter): string
    {
        $body = PacketBytes::encodeUint16($packetId) . PacketBytes::encodeString($topicFilter);

        return PacketBytes::assemblePacket(PacketType::Unsubscribe, self::SUBSCRIPTION_REQUIRED_FLAGS, $body);
    }

    public function encodePingRequest(): string
    {
        return PacketBytes::assemblePacket(PacketType::PingReq, 0, '');
    }

    public function encodeDisconnect(): string
    {
        return PacketBytes::assemblePacket(PacketType::Disconnect, 0, '');
    }
}
