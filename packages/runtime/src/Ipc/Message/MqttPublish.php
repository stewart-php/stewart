<?php

declare(strict_types=1);

namespace Stewart\Runtime\Ipc\Message;

use Stewart\Contracts\Mqtt\MqttMessage;
use Stewart\Runtime\Model\ResourceScope;

#[IpcMessage(tag: 'mqtt_publish')]
final readonly class MqttPublish implements WorkerMessage
{
    public function __construct(
        public MqttMessage $message,
        public ResourceScope $publisherScope,
    ) {}
}
