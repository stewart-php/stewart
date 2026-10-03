<?php

declare(strict_types=1);

namespace Stewart\Contracts\Connection;

use Stewart\Contracts\Time\Duration;
use Stewart\Contracts\Time\Instant;

final readonly class ConnectionRestored implements ConnectionEvent
{
    public function __construct(
        public Instant $restoredAt,
        public int $entityCount,
        public Duration $outage,
        public int $reconstructedChanges,
    ) {}
}
