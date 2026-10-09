<?php

declare(strict_types=1);

namespace Stewart\Runtime\Control\Protocol\Status;

use Stewart\Contracts\Time\Instant;
use Stewart\Runtime\Lifecycle\ComponentState;

final readonly class ComponentStatus
{
    public function __construct(
        public ComponentState $state,
        public Instant $since,
        public string $instance,
        public ?string $version = null,
        public ?int $protocol = null,
    ) {}
}
