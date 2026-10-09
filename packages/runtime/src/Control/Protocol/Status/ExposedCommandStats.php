<?php

declare(strict_types=1);

namespace Stewart\Runtime\Control\Protocol\Status;

use Stewart\Runtime\Model\ExposedCommandOutcome;

final readonly class ExposedCommandStats
{
    public function __construct(
        public ExposedCommandOutcome $outcome,
        public int $count,
    ) {}
}
