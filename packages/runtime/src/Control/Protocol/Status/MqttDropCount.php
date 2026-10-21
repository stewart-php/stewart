<?php

declare(strict_types=1);

namespace Stewart\Runtime\Control\Protocol\Status;

use Stewart\Runtime\Model\MqttDropReason;

final readonly class MqttDropCount
{
    public function __construct(
        public MqttDropReason $reason,
        public int $count,
    ) {}
}
