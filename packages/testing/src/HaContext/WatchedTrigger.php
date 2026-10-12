<?php

declare(strict_types=1);

namespace Stewart\Testing\HaContext;

use Stewart\Contracts\Trigger\Collection\HaTriggerCollection;

final readonly class WatchedTrigger
{
    /** @param array<string, mixed> $variables */
    public function __construct(
        public HaTriggerCollection $triggers,
        public array $variables,
    ) {}
}
