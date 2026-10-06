<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker;

use Stewart\Contracts\Time\Instant;
use Stewart\Runtime\Lifecycle\AppPauseSource;

final readonly class StoredAppPauseOverride
{
    public function __construct(
        public bool $paused,
        public Instant $since,
        public AppPauseSource $source,
    ) {}
}
