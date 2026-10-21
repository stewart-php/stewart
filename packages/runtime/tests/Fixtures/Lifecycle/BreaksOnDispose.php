<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Lifecycle;

use RuntimeException;
use Stewart\Contracts\App;
use Stewart\Contracts\Disposable;

final class BreaksOnDispose implements App, Disposable
{
    public function initialize(): void {}

    public function dispose(): void
    {
        throw new RuntimeException('dispose blew up');
    }
}
