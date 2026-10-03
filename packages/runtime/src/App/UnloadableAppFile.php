<?php

declare(strict_types=1);

namespace Stewart\Runtime\App;

use Throwable;

final readonly class UnloadableAppFile
{
    public function __construct(
        public string $path,
        public Throwable $cause,
    ) {}
}
