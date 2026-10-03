<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Broken\Comparable;

use Stewart\Contracts\App;
use Stewart\Contracts\Automation;

#[Automation(id: 'hall-light')]
final class Hyphen implements App
{
    public function initialize(): void {}

    public function dispose(): void {}
}
