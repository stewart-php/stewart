<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Wire;

use Stewart\Contracts\Wire\ListOf;

final readonly class Drawing
{
    /** @param list<Shape> $layers */
    public function __construct(
        public Shape $main,
        #[ListOf(Shape::class)]
        public array $layers,
        public ?Shape $outline,
    ) {}
}
