<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Wire;

use Countable;

final readonly class HoldsInterface
{
    public function __construct(public Countable $items) {}
}
