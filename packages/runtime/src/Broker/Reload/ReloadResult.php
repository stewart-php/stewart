<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker\Reload;

use Stewart\Contracts\Time\Instant;
use Stewart\Runtime\Lifecycle\ReloadOutcome;

final readonly class ReloadResult
{
    /** @param list<string> $settingsNeedingRestart */
    public function __construct(
        public ReloadOutcome $outcome,
        public Instant $finishedAt,
        public ?string $error = null,
        public array $settingsNeedingRestart = [],
    ) {}
}
