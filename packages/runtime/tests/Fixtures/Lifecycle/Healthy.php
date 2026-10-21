<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Lifecycle;

use Stewart\Contracts\App;
use Stewart\Contracts\Disposable;

final class Healthy implements App, Disposable
{
    public bool $initialized = false;

    public bool $disposed = false;

    public function initialize(): void
    {
        $this->initialized = true;
    }

    public function dispose(): void
    {
        $this->disposed = true;
    }
}
