<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Broken\NumericId;

use Stewart\Contracts\App;
use Stewart\Contracts\Automation;

#[Automation(id: '123')]
final class Numbered implements App
{
    public function initialize(): void {}

    public function dispose(): void {}
}
