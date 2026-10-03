<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Apps;

use Stewart\Contracts\App;
use Stewart\Contracts\Automation;
use Stewart\Contracts\HaContext;

#[Automation(id: 'echo')]
final class Relay implements App
{
    public function __construct(public readonly HaContext $ha) {}

    public function initialize(): void {}

    public function dispose(): void {}
}
