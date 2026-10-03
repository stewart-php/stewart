<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Wire;

final readonly class WithFragment
{
    public function __construct(
        public int $id,
        public Numbers $numbers,
    ) {}
}
