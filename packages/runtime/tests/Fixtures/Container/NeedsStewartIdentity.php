<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Container;

use Stewart\Contracts\App;
use Stewart\Contracts\Identity\StewartIdentity;

final readonly class NeedsStewartIdentity implements App
{
    public function __construct(public StewartIdentity $identity) {}

    public function initialize(): void {}

    public function dispose(): void {}
}
