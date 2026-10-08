<?php

declare(strict_types=1);

namespace Stewart\Runtime\Control\Protocol\Status;

use Stewart\Contracts\Time\Instant;

final readonly class DeployFailure
{
    public function __construct(
        public string $commit,
        public string $reason,
        public Instant $failedAt,
    ) {}
}
