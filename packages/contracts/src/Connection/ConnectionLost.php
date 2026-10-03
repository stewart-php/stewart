<?php

declare(strict_types=1);

namespace Stewart\Contracts\Connection;

use Stewart\Contracts\Time\Instant;

final readonly class ConnectionLost implements ConnectionEvent
{
    public function __construct(
        public Instant $lostAt,
        public string $reason,
    ) {}
}
