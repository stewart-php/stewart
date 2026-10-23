<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker\Reload;

use Stewart\Runtime\App\AppOrigin;

final readonly class ReloadCheckApp
{
    /** @param class-string $class */
    public function __construct(
        public string $id,
        public string $class,
        public AppOrigin $origin = AppOrigin::Scanned,
    ) {}
}
