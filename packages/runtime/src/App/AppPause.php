<?php

declare(strict_types=1);

namespace Stewart\Runtime\App;

use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Time\Instant;
use Stewart\Runtime\Lifecycle\AppPauseSource;

final readonly class AppPause
{
    public function __construct(
        public AppId $appId,
        public Instant $since,
        public AppPauseSource $source,
    ) {}
}
