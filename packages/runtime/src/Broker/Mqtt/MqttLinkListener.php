<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker\Mqtt;

use Stewart\Runtime\Model\MqttDropReason;

interface MqttLinkListener
{
    public function connected(): void;

    // Also for an attempt that never connected.
    public function connectionLost(string $reason): void;

    public function messageDropped(MqttDropReason $reason, string $topic): void;
}
