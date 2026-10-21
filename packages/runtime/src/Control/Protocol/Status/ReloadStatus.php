<?php

declare(strict_types=1);

namespace Stewart\Runtime\Control\Protocol\Status;

final readonly class ReloadStatus
{
    public function __construct(
        public bool $underway = false,
        public int $applied = 0,
        public int $partlyApplied = 0,
        public int $failed = 0,
        public ?ReloadAttempt $last = null,
    ) {}
}
