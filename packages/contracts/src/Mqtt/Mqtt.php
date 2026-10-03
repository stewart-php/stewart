<?php

declare(strict_types=1);

namespace Stewart\Contracts\Mqtt;

use Stewart\Contracts\EventStream;
use Stewart\Contracts\Exception\MqttException;
use Stewart\Contracts\Exception\SelectorException;

interface Mqtt
{
    /**
     * @param string|array<array-key, mixed> $payload
     * @throws MqttException
     */
    public function publish(string $topic, string|array $payload, MqttQos $qos = MqttQos::AtMostOnce, bool $retain = false): void;

    /**
     * @return EventStream<MqttMessage>
     * @throws SelectorException|MqttException
     */
    public function watchMessages(string $topicFilter): EventStream;
}
