<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Container;

use Stewart\Contracts\App;

final readonly class NeedsScalar implements App
{
    public function __construct(public string $entity) {}

    public function initialize(): void {}

    public function dispose(): void {}
}
