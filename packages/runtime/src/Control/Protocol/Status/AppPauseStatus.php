<?php

declare(strict_types=1);

namespace Stewart\Runtime\Control\Protocol\Status;

use Stewart\Contracts\Time\Instant;
use Stewart\Runtime\Lifecycle\AppPauseSource;

final readonly class AppPauseStatus
{
    public function __construct(
        public Instant $since,
        public AppPauseSource $source,
    ) {}
}
