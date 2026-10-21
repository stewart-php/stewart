<?php

declare(strict_types=1);

namespace Stewart\Runtime\Control\Protocol\Status;

use Stewart\Contracts\Time\Instant;
use Stewart\Contracts\Wire\ListOf;
use Stewart\Runtime\Lifecycle\ReloadOutcome;

final readonly class ReloadAttempt
{
    /** @param list<string> $settingsNeedingRestart */
    public function __construct(
        public ReloadOutcome $outcome,
        public Instant $finishedAt,
        public ?string $error = null,
        #[ListOf('string')]
        public array $settingsNeedingRestart = [],
    ) {}
}
