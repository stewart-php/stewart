<?php

declare(strict_types=1);

namespace Stewart\Runtime\App;

use Stewart\Runtime\Lifecycle\AppPauseOverridePersistence;
use Stewart\Runtime\Lifecycle\AppStateAfterReset;

final readonly class AppPauseResetOutcome
{
    public function __construct(
        public bool $overrideRemoved,
        public AppStateAfterReset $stateAfter,
        public AppPauseOverridePersistence $persistence,
    ) {}
}
