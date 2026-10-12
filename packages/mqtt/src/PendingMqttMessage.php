<?php

declare(strict_types=1);

namespace Stewart\Mqtt;

use Stewart\Contracts\Mqtt\MqttMessage;

final class PendingMqttMessage
{
    public private(set) int $sendAttempts = 0;

    public function __construct(public readonly MqttMessage $message) {}

    public function recordSendAttempt(): void
    {
        ++$this->sendAttempts;
    }
}
