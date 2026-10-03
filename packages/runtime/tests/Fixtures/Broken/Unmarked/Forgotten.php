<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Broken\Unmarked;

use Stewart\Contracts\App;

final class Forgotten implements App
{
    public function initialize(): void {}

    public function dispose(): void {}
}
