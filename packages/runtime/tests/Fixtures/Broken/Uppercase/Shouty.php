<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Broken\Uppercase;

use Stewart\Contracts\App;
use Stewart\Contracts\Automation;

#[Automation(id: 'HallLight')]
final class Shouty implements App
{
    public function initialize(): void {}

    public function dispose(): void {}
}
