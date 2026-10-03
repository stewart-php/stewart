<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Wire;

final readonly class NarrowUnion
{
    public function __construct(public int|string $value) {}
}
