<?php

declare(strict_types=1);

namespace Stewart\Mqtt\Packet;

use SensitiveParameter;
use Stewart\Contracts\Mqtt\MqttMessage;
use Stewart\Contracts\Time\Duration;

final readonly class ConnectPacket implements OutboundPacket
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

    public function __construct(
        public string $clientId,
        public Duration $keepalive,
        public ?string $username = null,
        #[SensitiveParameter]
        private ?string $password = null,
        public ?MqttMessage $will = null,
    ) {}

    public function encodePacket(): string
    {
        $flags = self::CLEAN_SESSION_FLAG;
        $payload = PacketBytes::encodeString($this->clientId);

        if ($this->will !== null) {
            $flags |= self::WILL_FLAG | ($this->will->qos->value << self::WILL_QOS_SHIFT) | ($this->will->retain ? self::WILL_RETAIN_FLAG : 0);
            $payload .= PacketBytes::encodeString($this->will->topic) . PacketBytes::encodeString($this->will->payload);
        }

        // MQTT 3.1.1 allows a password only together with a username.
        if ($this->username !== null) {
            $flags |= self::USERNAME_FLAG;
            $payload .= PacketBytes::encodeString($this->username);

            if ($this->password !== null) {
                $flags |= self::PASSWORD_FLAG;
                $payload .= PacketBytes::encodeString($this->password);
            }
        }

        $variableHeader = PacketBytes::encodeString(self::PROTOCOL_NAME)
            . \chr(self::PROTOCOL_LEVEL)
            . \chr($flags)
            . PacketBytes::encodeUint16(min(self::MAX_KEEPALIVE_SECONDS, (int) ceil($this->keepalive->toSeconds())));

        return PacketBytes::assemblePacket(PacketType::Connect, 0, $variableHeader . $payload);
    }
}
