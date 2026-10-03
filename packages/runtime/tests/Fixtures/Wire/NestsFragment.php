<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Wire;

final readonly class NestsFragment
{
    public function __construct(public WithFragment $inner) {}
}
