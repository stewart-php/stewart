<?php

declare(strict_types=1);

namespace Stewart\Runtime\App;

final readonly class UnmarkedApp
{
    /** @param class-string $class */
    public function __construct(public string $class) {}
}
