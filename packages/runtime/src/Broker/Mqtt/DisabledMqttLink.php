<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker\Mqtt;

use Psr\Log\LoggerInterface;
use Stewart\Contracts\Mqtt\MqttMessage;

final readonly class DisabledMqttLink implements MqttLink
{
    public function __construct(private LoggerInterface $logger) {}

    public function startInBackground(MqttMessageRouter $router): void {}

    public function publishMessage(MqttMessage $message): void
    {
        $this->logger->warning('MQTT message dropped; mqtt.url is not set', ['topic' => $message->topic]);
    }

    public function subscribeFilter(string $topicFilter): void {}

    public function unsubscribeFilter(string $topicFilter): void {}

    public function close(): void {}
}
