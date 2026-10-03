<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Lifecycle;

use Amp\CancelledException;
use Stewart\Contracts\App;

final class CancelsOnInitialize implements App
{
    public function initialize(): void
    {
        throw new CancelledException();
    }

    public function dispose(): void {}
}
