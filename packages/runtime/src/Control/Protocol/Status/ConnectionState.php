<?php

declare(strict_types=1);

namespace Stewart\Runtime\Control\Protocol\Status;

use Stewart\Contracts\Time\Duration;
use Stewart\Contracts\Time\Instant;
use Stewart\Runtime\Lifecycle\ConnectionPhase;

final readonly class ConnectionState
{
    public function __construct(
        public ConnectionPhase $phase = ConnectionPhase::Connecting,
        public ?Instant $since = null,
        public int $reconnects = 0,
        public ?Duration $lastOutage = null,
    ) {}
}
