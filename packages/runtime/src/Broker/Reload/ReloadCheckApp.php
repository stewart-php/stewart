<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker\Reload;

final readonly class ReloadCheckApp
{
    /** @param class-string $class */
    public function __construct(
        public string $id,
        public string $class,
    ) {}
}
