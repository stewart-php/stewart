<?php

declare(strict_types=1);

namespace Stewart\Testing\Tests\Fixtures\App;

use Stewart\Contracts\HaContext;

final readonly class FakeEntities
{
    public function __construct(public HaContext $ha) {}
}
