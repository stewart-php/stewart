<?php

declare(strict_types=1);

namespace Stewart\Mqtt\Tests\Fixtures;

use Stewart\Runtime\Broker\Mqtt\MqttLinkListener;
use Stewart\Runtime\Model\MqttDropReason;

final class RecordingMqttLinkListener implements MqttLinkListener
{
    public private(set) int $connects = 0;

    /** @var list<string> */
    public private(set) array $losses = [];

    /** @var list<array{MqttDropReason, string}> */
    public private(set) array $drops = [];

    public function connected(): void
    {
        ++$this->connects;
    }

    public function connectionLost(string $reason): void
    {
        $this->losses[] = $reason;
    }

    public function messageDropped(MqttDropReason $reason, string $topic): void
    {
        $this->drops[] = [$reason, $topic];
    }
}
