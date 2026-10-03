<?php

declare(strict_types=1);

namespace Stewart\Store;

use Stewart\Contracts\Time\Instant;

final readonly class StoreHealth
{
    public function __construct(
        public bool $available,
        public ?string $lastFailure = null,
        public ?Instant $lastFailureAt = null,
    ) {}
}
