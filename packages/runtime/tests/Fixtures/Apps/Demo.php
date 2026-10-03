<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Apps;

use Stewart\Contracts\App;
use Stewart\Contracts\Automation;
use Stewart\Contracts\HaContext;

#[Automation(id: 'demo')]
final class Demo implements App
{
    public function __construct(
        public readonly HaContext $ha,
        public readonly string $watch = 'light.default',
        public readonly ?string $light = null,
    ) {}

    public function initialize(): void {}

    public function dispose(): void {}
}
