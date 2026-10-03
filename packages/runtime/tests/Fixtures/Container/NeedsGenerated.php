<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Container;

use Stewart\Contracts\App;
use Stewart\Runtime\Tests\Fixtures\Generated\Code\Entities;
use Stewart\Runtime\Tests\Fixtures\Generated\Code\Services;

final readonly class NeedsGenerated implements App
{
    public function __construct(
        public Entities $entities,
        public Services $services,
    ) {}

    public function initialize(): void {}

    public function dispose(): void {}
}
