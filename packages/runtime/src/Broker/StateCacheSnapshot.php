<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker;

use Stewart\Runtime\Ipc\Wire\EntityStatesFragment;

final readonly class StateCacheSnapshot
{
    public function __construct(
        public EntityStatesFragment $states,
        public int $revision,
    ) {}
}
