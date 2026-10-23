<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Container;

use Stewart\Contracts\App;
use Stewart\Runtime\Tests\Fixtures\Generated\Code\Actions;
use Stewart\Runtime\Tests\Fixtures\Generated\Code\Entities;

final readonly class NeedsGenerated implements App
{
    public function __construct(
        public Entities $entities,
        public Actions $actions,
    ) {}

    public function initialize(): void {}
}
