<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Wire;

final readonly class Square extends Shape
{
    public function __construct(
        public int $side,
        string $name,
    ) {
        parent::__construct($name);
    }
}
