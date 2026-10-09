<?php

declare(strict_types=1);

namespace Stewart\Runtime\Control\Assembler;

use Stewart\Runtime\Broker\Component\ComponentTracker;
use Stewart\Runtime\Config\ExposeConfig;
use Stewart\Runtime\Control\Protocol\Status\ComponentStatus;

final readonly class ComponentStatusBuilder
{
    public function __construct(
        private ComponentTracker $tracker,
        private ExposeConfig $expose,
    ) {}

    public function buildComponentStatus(): ComponentStatus
    {
        $detection = $this->tracker->detection;

        return new ComponentStatus(
            state: $detection->state,
            since: $detection->since,
            instance: $this->expose->instance->value,
            version: $detection->version?->componentVersion,
            protocol: $detection->version?->protocol,
        );
    }
}
