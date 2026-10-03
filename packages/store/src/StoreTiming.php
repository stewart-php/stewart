<?php

declare(strict_types=1);

namespace Stewart\Store;

use Stewart\Contracts\Time\Duration;

final readonly class StoreTiming
{
    public function __construct(
        public Duration $timeout,
        public Duration $recoveryInterval,
    ) {}
}
