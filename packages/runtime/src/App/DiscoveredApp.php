<?php

declare(strict_types=1);

namespace Stewart\Runtime\App;

use Stewart\Contracts\App\AppId;

final readonly class DiscoveredApp
{
    /** @param class-string $class */
    public function __construct(
        public string $class,
        public AppId $id,
    ) {}
}
