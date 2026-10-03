<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Container;

use Stewart\Contracts\App;
use Stewart\Runtime\Tests\Fixtures\Generated\NotGeneratedYet;

final readonly class NeedsMissingClass implements App
{
    public function __construct(public NotGeneratedYet $entities) {}

    public function initialize(): void {}

    public function dispose(): void {}
}
