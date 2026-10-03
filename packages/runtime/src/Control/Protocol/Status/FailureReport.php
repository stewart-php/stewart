<?php

declare(strict_types=1);

namespace Stewart\Runtime\Control\Protocol\Status;

use Stewart\Contracts\Time\Instant;
use Stewart\Runtime\Lifecycle\AppFailurePhase;

final readonly class FailureReport
{
    public function __construct(
        public AppFailurePhase $phase,
        public string $class,
        public string $message,
        public ?string $origin,
        public ?string $reason,
        public Instant $at,
    ) {}
}
