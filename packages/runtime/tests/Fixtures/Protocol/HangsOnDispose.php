<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Protocol;

use Amp\DeferredFuture;
use Stewart\Contracts\App;
use Stewart\Contracts\Automation;

#[Automation(id: 'hangs-on-dispose')]
final readonly class HangsOnDispose implements App
{
    public function initialize(): void {}

    public function dispose(): void
    {
        new DeferredFuture()->getFuture()->await();
    }
}
