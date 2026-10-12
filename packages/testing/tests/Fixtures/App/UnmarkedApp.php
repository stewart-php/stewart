<?php

declare(strict_types=1);

namespace Stewart\Testing\Tests\Fixtures\App;

use Stewart\Contracts\App;

final readonly class UnmarkedApp implements App
{
    public function initialize(): void {}

    public function dispose(): void {}
}
