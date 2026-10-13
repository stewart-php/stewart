<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Wire;

use Stewart\Contracts\Wire\WireVariants;

#[WireVariants('kind', ['circle' => Circle::class, 'square' => Square::class])]
abstract readonly class Shape
{
    public function __construct(public string $name) {}
}
