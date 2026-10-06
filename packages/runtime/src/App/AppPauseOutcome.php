<?php

declare(strict_types=1);

namespace Stewart\Runtime\App;

use Stewart\Runtime\Lifecycle\AppPauseOverridePersistence;

final readonly class AppPauseOutcome
{
    public function __construct(
        public bool $changed,
        public AppPauseOverridePersistence $persistence,
    ) {}
}
