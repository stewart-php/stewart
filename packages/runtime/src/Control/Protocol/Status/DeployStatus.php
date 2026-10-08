<?php

declare(strict_types=1);

namespace Stewart\Runtime\Control\Protocol\Status;

use Stewart\Contracts\Time\Instant;

final readonly class DeployStatus
{
    public function __construct(
        public string $commit,
        public ?Instant $lastPolledAt = null,
        public int $failures = 0,
        public ?DeployFailure $lastFailure = null,
    ) {}
}
