<?php

declare(strict_types=1);

namespace Stewart\Runtime\App;

use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Time\Instant;
use Stewart\Runtime\Lifecycle\AppPauseSource;

final readonly class AppPauseOverride
{
    public function __construct(
        public AppId $appId,
        public bool $paused,
        public Instant $since,
        public AppPauseSource $source,
    ) {}
}
