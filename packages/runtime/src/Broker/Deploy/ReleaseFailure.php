<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker\Deploy;

use Stewart\Contracts\Time\Instant;

final readonly class ReleaseFailure
{
    public function __construct(
        public CommitId $commit,
        public string $reason,
        public Instant $failedAt,
    ) {}
}
