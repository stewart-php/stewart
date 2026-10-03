<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker\Message;

use Stewart\Runtime\Broker\Mqtt\MqttLink;
use Stewart\Runtime\Broker\WorkerHandle;
use Stewart\Runtime\Ipc\Message\MqttPublish;
use Stewart\Runtime\Ipc\Message\WorkerMessage;

/** @implements WorkerMessageHandler<MqttPublish> */
final readonly class MqttPublishHandler implements WorkerMessageHandler
{
    public function __construct(private MqttLink $link) {}

    public function handledMessageClass(): string
    {
        return MqttPublish::class;
    }

    /** @param MqttPublish $message */
    public function handle(WorkerHandle $handle, WorkerMessage $message): void
    {
        $this->link->publishMessage($message->message);
    }
}
