<?php

declare(strict_types=1);

namespace Stewart\Runtime\Container;

use Stewart\Runtime\Container\Collection\AppBuildFailureCollection;

final readonly class AppContainerBuild
{
    public function __construct(
        public TypedContainer $container,
        public AppBuildFailureCollection $failures,
    ) {}
}
