<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker\Component;

use Stewart\Client\Component\ComponentVersion;
use Stewart\Contracts\Time\Clock;
use Stewart\Runtime\Lifecycle\ComponentState;

final class ComponentTracker
{
    public private(set) ComponentDetection $detection;

    public function __construct(private readonly Clock $clock)
    {
        $this->detection = new ComponentDetection(ComponentState::Unchecked, null, $clock->getNow());
    }

    public function recordState(ComponentState $state, ?ComponentVersion $version): void
    {
        $since = $state === $this->detection->state ? $this->detection->since : $this->clock->getNow();
        $this->detection = new ComponentDetection($state, $version, $since);
    }

    public function recordStateKeepingVersion(ComponentState $state): void
    {
        $this->recordState($state, $this->detection->version);
    }

    public function isActive(): bool
    {
        return $this->detection->state === ComponentState::Active;
    }
}
