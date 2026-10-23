<?php

declare(strict_types=1);

namespace Stewart\Testing\Tests\Fixtures\App;

use Stewart\Contracts\App;
use Stewart\Contracts\Automation;

#[Automation(id: 'dispose-without-disposable')]
final class DisposeWithoutDisposableApp implements App
{
    public private(set) bool $disposed = false;

    public function initialize(): void {}

    public function dispose(): void
    {
        $this->disposed = true;
    }
}
