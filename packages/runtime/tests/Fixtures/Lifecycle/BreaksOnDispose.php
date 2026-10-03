<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Lifecycle;

use RuntimeException;
use Stewart\Contracts\App;

final class BreaksOnDispose implements App
{
    public function initialize(): void {}

    public function dispose(): void
    {
        throw new RuntimeException('dispose blew up');
    }
}
