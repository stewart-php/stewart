<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Wire;

final readonly class HiddenConstructor
{
    private function __construct(public string $name) {}
}
