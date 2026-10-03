<?php

declare(strict_types=1);

namespace Stewart\Runtime\Control\Protocol\Status;

use Stewart\Contracts\Time\Instant;

final readonly class DaemonInfo
{
    public function __construct(
        public int $pid,
        public Instant $startedAt,
        public int $memoryBytes,
        public string $version,
        public string $timeZone,
        public int $entities,
        public ?string $haVersion,
    ) {}
}
