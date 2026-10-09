<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker\Component;

use Stewart\Client\Component\ComponentVersion;
use Stewart\Contracts\Time\Instant;

final readonly class ComponentDetection
{
    public function __construct(
        public ComponentState $state,
        public ?ComponentVersion $version,
        public Instant $since,
    ) {}
}
