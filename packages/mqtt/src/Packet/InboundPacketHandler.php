<?php

declare(strict_types=1);

namespace Stewart\Mqtt\Packet;

use Stewart\Mqtt\Exception\MqttClientException;

interface InboundPacketHandler
{
    /** @throws MqttClientException */
    public function handleConnAck(ConnAckPacket $packet): void;

    /** @throws MqttClientException */
    public function handlePublish(PublishPacket $packet): void;

    /** @throws MqttClientException */
    public function handlePubAck(PubAckPacket $packet): void;

    /** @throws MqttClientException */
    public function handleSubAck(SubAckPacket $packet): void;

    /** @throws MqttClientException */
    public function handleUnsubAck(UnsubAckPacket $packet): void;

    /** @throws MqttClientException */
    public function handlePingResp(PingRespPacket $packet): void;
}
