<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Broken\Duplicate;

use Stewart\Contracts\App;
use Stewart\Contracts\Automation;

#[Automation(id: 'twice')]
final class First implements App
{
    public function initialize(): void {}

    public function dispose(): void {}
}
