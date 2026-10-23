<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Templates;

use Stewart\Contracts\App;
use Stewart\Contracts\AppTemplate;
use Stewart\Contracts\HaContext;

#[AppTemplate]
final readonly class RoomLight implements App
{
    public function __construct(
        public HaContext $ha,
        public string $light,
    ) {}

    public function initialize(): void {}
}
