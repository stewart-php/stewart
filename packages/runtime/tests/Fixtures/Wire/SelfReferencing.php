<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Wire;

final readonly class SelfReferencing
{
    public function __construct(public ?SelfReferencing $next) {}
}
