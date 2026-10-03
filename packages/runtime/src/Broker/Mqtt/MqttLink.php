<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker\Mqtt;

use Stewart\Contracts\Mqtt\MqttMessage;

interface MqttLink
{
    public function startInBackground(MqttMessageRouter $router): void;

    public function publishMessage(MqttMessage $message): void;

    public function subscribeFilter(string $topicFilter): void;

    public function unsubscribeFilter(string $topicFilter): void;

    public function close(): void;
}
